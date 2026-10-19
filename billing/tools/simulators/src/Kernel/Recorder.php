<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;

/**
 * Recordings of a provider's responses (`tools/simulators/<provider>/recordings/<operation>.json`), tagged `source:
 * live` (from a real sandbox, `simulate:record`) or `source: docs` (written from the reference), and the shape check
 * that compares what a simulator serves with them: key paths and types.
 *
 * A recording keeps the shape (key paths and types) of the answer as it came, taken before any masking, so a masked
 * value never hides a change of type. The payment rule is applied when `$payments` is on (a RedactsPayments simulator).
 */
final class Recorder
{
    public function __construct(private readonly string $directory, private readonly Redactor $redactor = new Redactor, private readonly bool $payments = true) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @param  list<string>  $secrets  values to mask wherever they appear (the credentials used)
     */
    public function record(string $operation, array $response, string $source, array $secrets = []): string
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        $path = $this->directory.'/'.preg_replace('/[^a-z0-9_.-]/i', '_', $operation).'.json';
        $redacted = $this->redactor->redact($response, $secrets);
        file_put_contents($path, json_encode([
            'source' => $source,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'shape' => self::shape($response),
            'response' => $this->payments ? $this->redactor->redactPayment($redacted) : $redacted,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }

    /**
     * Every key path with its type: `data.acct.*.user` => `string`. List items share one path (`*`). A value the
     * recording masked has the type `redacted`, which matches any type.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, string>
     */
    public static function shape(array $value, string $prefix = ''): array
    {
        $shape = [];

        foreach ($value as $key => $item) {
            $path = ltrim($prefix.'.'.(array_is_list($value) ? '*' : $key), '.');
            $shape[$path] = match (true) {
                is_array($item) => array_is_list($item) ? 'list' : 'object',
                in_array($item, [Redactor::MASK, Redactor::CARD_MASK], true) => 'redacted',
                default => get_debug_type($item),
            };

            if (is_array($item)) {
                $shape = [...$shape, ...self::shape($item, $path)];
            }
        }

        ksort($shape);

        return $shape;
    }

    /**
     * Where the actual response differs from the recording: missing paths, extra paths and changed types.
     *
     * @param  array<array-key, mixed>  $recorded
     * @param  array<array-key, mixed>  $actual
     * @return list<string>
     */
    public static function differences(array $recorded, array $actual): array
    {
        return self::shapeDifferences(self::shape($recorded), $actual);
    }

    /**
     * Where an answer differs from a recording file (`source`, `shape`, `response`): against its stored shape, or the
     * shape of its response for a recording that has none.
     *
     * @param  array<string, mixed>  $recording
     * @param  array<array-key, mixed>  $actual
     * @return list<string>
     */
    public static function againstRecording(array $recording, array $actual): array
    {
        return is_array($recording['shape'] ?? null)
            ? self::shapeDifferences(array_map('strval', $recording['shape']), $actual)
            : self::differences((array) ($recording['response'] ?? []), $actual);
    }

    /**
     * @param  array<string, string>  $expected  path => type
     * @param  array<array-key, mixed>  $actual
     * @return list<string>
     */
    public static function shapeDifferences(array $expected, array $actual): array
    {
        $found = self::shape($actual);
        $differences = [];

        foreach ($expected as $path => $type) {
            if (! isset($found[$path])) {
                $differences[] = "missing {$path}";
            } elseif ($found[$path] !== $type && ! in_array('null', [$type, $found[$path]], true) && ! in_array('redacted', [$type, $found[$path]], true)) {
                $differences[] = "{$path} is {$found[$path]}, recorded {$type}";
            }
        }

        foreach (array_diff_key($found, $expected) as $path => $type) {
            $differences[] = "extra {$path}";
        }

        return $differences;
    }
}
