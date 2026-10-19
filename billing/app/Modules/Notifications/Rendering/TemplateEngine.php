<?php

namespace App\Modules\Notifications\Rendering;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Notifications\Support\MailClock;
use App\Support\Currencies\MoneyFormatter;

/**
 * Sandboxed merge-field engine for staff-editable templates (spec §3). It never executes code:
 * templates are parsed into a small tree and rendered against a plain array context.
 *
 *   {{ client.first_name }}                 escaped output
 *   {{ ticket.message_html | raw }}         unescaped, only for fields ending in "_html" (pre-sanitised)
 *   {{ invoice.due_date | date }}           filters: money, date, upper, lower, default('x'), raw
 *   {% if invoice.balance %}…{% else %}…{% endif %}   ({% if not path %} too)
 *   {% for item in invoice.items %}{{ item.description }}{% endfor %}
 *
 * Only array keys are traversed (no objects, no method calls). Unknown variables render empty and are
 * reported as warnings; syntax errors throw TemplateSyntaxError.
 *
 * @phpstan-type Filter array{name: string, args: list<array{literal: bool, value: string}>}
 * @phpstan-type Node array{type: 'text', text: string}|array{type: 'var', path: string, filters: list<Filter>, line: int}|array{type: 'if', path: string, negate: bool, then: list<mixed>, else: list<mixed>}|array{type: 'for', var: string, path: string, body: list<mixed>}
 */
final class TemplateEngine
{
    public const FILTERS = ['money', 'date', 'upper', 'lower', 'default', 'raw'];

    /** Resource limits (security review PR #28, M1): a template can't pin a worker or exhaust memory. */
    public const MAX_DEPTH = 10;

    public const MAX_LOOP_ITERATIONS = 10_000;

    public const MAX_OUTPUT_BYTES = 262_144;

    private int $iterations = 0;

    private int $outputBytes = 0;

    private const PATH = '[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*';

    /** Characters that would otherwise turn a merged value into Markdown syntax. */
    private const MARKDOWN_SPECIALS = ['\\', '`', '*', '_', '[', ']', '|', '~'];

    /** @var list<string> */
    private array $warnings = [];

    private bool $escape = true;

    /** @var array<string, mixed> */
    private array $options = [];

    /**
     * Throws when the source does not parse; returns the variable paths it references.
     *
     * @return list<string>
     */
    public function validate(string $source): array
    {
        return array_values(array_unique($this->collectPaths($this->parse($source))));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  bool  $escape  true: HTML/Markdown-escape values (body for the HTML part); false: plain text
     * @param  array{date_format?: string, locale?: string, currency?: string}  $options  date_format defaults to DateFormats::current()
     */
    public function render(string $source, array $context, bool $escape = true, array $options = []): RenderResult
    {
        $this->warnings = [];
        $this->escape = $escape;
        $this->options = $options;
        $this->iterations = 0;
        $this->outputBytes = 0;

        $output = $this->renderNodes($this->parse($source), [$context]);

        return new RenderResult($output, array_values(array_unique($this->warnings)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $source): array
    {
        $parts = preg_split('/(\{\{.*?\}\}|\{%.*?%\})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE) ?: [];

        /** @var non-empty-list<ParseFrame> $stack */
        $stack = [new ParseFrame];

        foreach ($parts as [$part, $offset]) {
            if ($part === '') {
                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $frame = $stack[count($stack) - 1];

            if (str_starts_with($part, '{{') && str_ends_with($part, '}}')) {
                $frame->nodes[] = $this->parseVariable(trim(substr($part, 2, -2)), $line);

                continue;
            }

            if (str_starts_with($part, '{%') && str_ends_with($part, '%}')) {
                $stack = $this->parseTag(trim(substr($part, 2, -2)), $line, $stack);

                continue;
            }

            $positions = array_filter([strpos($part, '{{'), strpos($part, '{%')], fn (int|false $position): bool => $position !== false);

            if ($positions !== []) {
                throw TemplateSyntaxError::at($line + substr_count(substr($part, 0, min($positions)), "\n"), 'Unclosed placeholder — every "{{" needs "}}" and every "{%" needs "%}".');
            }

            $frame->nodes[] = ['type' => 'text', 'text' => $part];
        }

        if (count($stack) > 1) {
            $open = $stack[count($stack) - 1];

            throw TemplateSyntaxError::at($open->line, sprintf('"{%% %s %%}" is never closed with "{%% end%s %%}".', $open->type(), $open->type()));
        }

        return $stack[0]->nodes;
    }

    /**
     * @param  non-empty-list<ParseFrame>  $stack
     * @return non-empty-list<ParseFrame>
     */
    private function parseTag(string $tag, int $line, array $stack): array
    {
        $path = self::PATH;
        $current = $stack[count($stack) - 1];

        if ((str_starts_with($tag, 'if ') || str_starts_with($tag, 'for ')) && count($stack) > self::MAX_DEPTH) {
            throw TemplateSyntaxError::at($line, 'Blocks are nested more than '.self::MAX_DEPTH.' levels deep.');
        }

        if (preg_match("/^if\s+(not\s+)?({$path})$/", $tag, $match)) {
            $stack[] = new ParseFrame(['type' => 'if', 'path' => $match[2], 'negate' => $match[1] !== '', 'then' => [], 'else' => []], 'then', $line);

            return $stack;
        }

        if (preg_match("/^for\s+([a-z_][a-z0-9_]*)\s+in\s+({$path})$/", $tag, $match)) {
            $stack[] = new ParseFrame(['type' => 'for', 'var' => $match[1], 'path' => $match[2], 'body' => []], 'body', $line);

            return $stack;
        }

        if ($tag === 'else') {
            if ($current->type() !== 'if' || $current->branch !== 'then' || $current->block === null) {
                throw TemplateSyntaxError::at($line, '"{% else %}" without a matching "{% if %}".');
            }

            $current->block['then'] = $current->nodes;
            $current->nodes = [];
            $current->branch = 'else';

            return $stack;
        }

        if ($tag === 'endif' || $tag === 'endfor') {
            $type = substr($tag, 3);

            if ($current->type() !== $type || $current->block === null || count($stack) < 2) {
                throw TemplateSyntaxError::at($line, "\"{% {$tag} %}\" without a matching \"{% {$type} %}\".");
            }

            array_pop($stack);
            $block = $current->block;
            $block[$current->branch] = $current->nodes;
            $stack[count($stack) - 1]->nodes[] = $block;

            return $stack;
        }

        throw TemplateSyntaxError::at($line, "Unknown tag \"{% {$tag} %}\". Use if, else, endif, for … in …, endfor.");
    }

    /**
     * @return array<string, mixed>
     */
    private function parseVariable(string $expression, int $line): array
    {
        $segments = array_map('trim', $this->splitFilters($expression));
        $path = array_shift($segments) ?? '';

        if (! preg_match('/^'.self::PATH.'$/', $path)) {
            throw TemplateSyntaxError::at($line, "\"{{ {$expression} }}\" is not a valid merge field.");
        }

        $filters = [];

        foreach ($segments as $segment) {
            if (! preg_match('/^([a-z_]+)(?:\((.*)\))?$/s', $segment, $match)) {
                throw TemplateSyntaxError::at($line, "Malformed filter \"{$segment}\".");
            }

            if (! in_array($match[1], self::FILTERS, true)) {
                throw TemplateSyntaxError::at($line, "Unknown filter \"{$match[1]}\". Available: ".implode(', ', self::FILTERS).'.');
            }

            $filters[] = ['name' => $match[1], 'args' => $this->parseArguments($match[2] ?? '', $line)];
        }

        return ['type' => 'var', 'path' => $path, 'filters' => $filters, 'line' => $line];
    }

    /**
     * Splits on "|" outside quotes.
     *
     * @return list<string>
     */
    private function splitFilters(string $expression): array
    {
        $segments = [];
        $buffer = '';
        $quote = null;

        foreach (mb_str_split($expression) as $character) {
            if ($quote !== null) {
                $quote = $character === $quote ? null : $quote;
            } elseif ($character === '\'' || $character === '"') {
                $quote = $character;
            } elseif ($character === '|') {
                $segments[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $segments[] = $buffer;

        return $segments;
    }

    /**
     * @return list<array{literal: bool, value: string}>
     */
    private function parseArguments(string $arguments, int $line): array
    {
        $arguments = trim($arguments);

        if ($arguments === '') {
            return [];
        }

        preg_match_all('/\s*(?:\'([^\']*)\'|"([^"]*)"|('.self::PATH.')|(-?\d+(?:\.\d+)?))\s*(?:,|$)/A', $arguments, $matches, PREG_SET_ORDER);
        $parsed = [];
        $consumed = 0;

        foreach ($matches as $match) {
            $consumed += strlen($match[0]);

            if (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '' || str_starts_with(ltrim($match[0]), '\'') || str_starts_with(ltrim($match[0]), '"')) {
                $parsed[] = ['literal' => true, 'value' => ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')];
            } elseif (($match[3] ?? '') !== '') {
                $parsed[] = ['literal' => false, 'value' => $match[3]];
            } else {
                $parsed[] = ['literal' => true, 'value' => $match[4] ?? ''];
            }
        }

        if ($consumed !== strlen($arguments)) {
            throw TemplateSyntaxError::at($line, "Malformed filter arguments \"({$arguments})\".");
        }

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $loopVariables
     * @return list<string>
     */
    private function collectPaths(array $nodes, array $loopVariables = []): array
    {
        $paths = [];

        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'var':
                    $paths[] = $node['path'];

                    // Paths used as filter arguments count too: default(auth.reset_url) must be checked like the field.
                    foreach ($node['filters'] as $filter) {
                        foreach ($filter['args'] as $argument) {
                            if (! $argument['literal']) {
                                $paths[] = $argument['value'];
                            }
                        }
                    }
                    break;
                case 'if':
                    $paths = [...$paths, $node['path'], ...$this->collectPaths($node['then'], $loopVariables), ...$this->collectPaths($node['else'], $loopVariables)];
                    break;
                case 'for':
                    $paths = [...$paths, $node['path'], ...$this->collectPaths($node['body'], [...$loopVariables, $node['var']])];
                    break;
            }
        }

        return array_values(array_filter($paths, fn (string $path): bool => ! in_array(explode('.', $path)[0], $loopVariables, true)));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $scopes  innermost last
     */
    private function renderNodes(array $nodes, array $scopes): string
    {
        $output = '';

        foreach ($nodes as $node) {
            $output .= match ($node['type']) {
                'text' => $this->counted($node['text']),
                'var' => $this->counted($this->renderVariable($node, $scopes)),
                'if' => $this->renderNodes($this->truthy($this->lookup($node['path'], $scopes)) !== $node['negate'] ? $node['then'] : $node['else'], $scopes),
                'for' => $this->renderLoop($node, $scopes),
                default => '',
            };
        }

        return $output;
    }

    private function counted(string $chunk): string
    {
        $this->outputBytes += strlen($chunk);

        if ($this->outputBytes > self::MAX_OUTPUT_BYTES) {
            throw new TemplateSyntaxError('The rendered email is larger than '.intdiv(self::MAX_OUTPUT_BYTES, 1024).' KB.');
        }

        return $chunk;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $scopes
     */
    private function renderLoop(array $node, array $scopes): string
    {
        $items = $this->lookup($node['path'], $scopes);

        if ($items === null) {
            return '';
        }

        if (! is_array($items)) {
            $this->warnings[] = "\"{$node['path']}\" is not a list.";

            return '';
        }

        $output = '';

        foreach (array_values($items) as $item) {
            if (++$this->iterations > self::MAX_LOOP_ITERATIONS) {
                throw new TemplateSyntaxError('The template loops more than '.self::MAX_LOOP_ITERATIONS.' times in total.');
            }

            $output .= $this->renderNodes($node['body'], [...$scopes, [$node['var'] => $item]]);
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $scopes
     */
    private function renderVariable(array $node, array $scopes): string
    {
        $value = $this->lookup($node['path'], $scopes);
        $raw = false;

        foreach ($node['filters'] as $filter) {
            if ($filter['name'] === 'raw') {
                if (str_ends_with($node['path'], '_html')) {
                    $raw = true;
                } else {
                    $this->warnings[] = "\"raw\" is only allowed on pre-sanitised *_html fields; \"{$node['path']}\" was escaped.";
                }

                continue;
            }

            $value = $this->applyFilter($filter['name'], $value, array_values(array_map(
                fn (array $argument): mixed => $argument['literal'] ? $argument['value'] : $this->lookup($argument['value'], $scopes),
                $filter['args'],
            )));
        }

        if ($value instanceof SensitiveValue) {
            // Never printed by the engine: the composer decides where (if anywhere) such a value may appear.
            $this->warnings[] = "\"{$node['path']}\" is an access link and can't be printed here.";

            return '';
        }

        if (is_array($value) || is_object($value)) {
            $this->warnings[] = "\"{$node['path']}\" is a list; use {% for %} to print it.";

            return '';
        }

        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };

        if (! $this->escape || $raw) {
            return $text;
        }

        $escaped = str_replace(self::MARKDOWN_SPECIALS, array_map(fn (string $character): string => '\\'.$character, self::MARKDOWN_SPECIALS), e($text));

        // Values never start Markdown blocks (security review L1): a leading heading, list, quote or
        // setext marker is escaped, and line breaks become <br> without a real newline.
        $escaped = (string) preg_replace(['/^(\s*)([#>+=-])/', '/^(\s*\d+)([.)])/'], '$1\\\\$2', $escaped);

        return str_replace(["\r\n", "\r", "\n"], '<br>', $escaped);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function applyFilter(string $filter, mixed $value, array $arguments): mixed
    {
        return match ($filter) {
            'upper' => is_scalar($value) ? mb_strtoupper((string) $value) : $value,
            'lower' => is_scalar($value) ? mb_strtolower((string) $value) : $value,
            'default' => $value === null || $value === '' || $value === [] ? ($arguments[0] ?? '') : $value,
            'money' => $this->formatMoney($value, $arguments[0] ?? null),
            'date' => $this->formatDate($value, $arguments[0] ?? null),
            default => $value,
        };
    }

    private function formatMoney(mixed $value, mixed $currency): mixed
    {
        $currency = is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : ($this->options['currency'] ?? null);

        if ((is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) && $currency !== null) {
            return app(MoneyFormatter::class)->amount((int) $value, $currency);
        }

        return $value;
    }

    /**
     * The date setting's format, in the company's time zone (MailClock); a value that is not a date is left as is.
     */
    private function formatDate(mixed $value, mixed $format): mixed
    {
        $formatted = MailClock::date($value, is_string($format) && $format !== '' ? $format : ($this->options['date_format'] ?? DateFormats::current()), $this->options['locale'] ?? 'en');

        return $formatted ?? $value;
    }

    /**
     * @param  list<array<string, mixed>>  $scopes
     */
    private function lookup(string $path, array $scopes): mixed
    {
        $segments = explode('.', $path);

        for ($index = count($scopes) - 1; $index >= 0; $index--) {
            if (! array_key_exists($segments[0], $scopes[$index])) {
                continue;
            }

            $value = $scopes[$index][$segments[0]];

            foreach (array_slice($segments, 1) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $this->warnings[] = "Unknown variable \"{$path}\".";

                    return null;
                }

                $value = $value[$segment];
            }

            return $value;
        }

        $this->warnings[] = "Unknown variable \"{$path}\".";

        return null;
    }

    private function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === '0' || $value === []);
    }
}
