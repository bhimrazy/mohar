<?php

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/*
 * While Nepal VAT mode is on, only the note issuer writes value adjustments on a numbered invoice (vat-register §5.4,
 * ADR 0024 §10). The old paths still write them for invoices without a number, so every method that writes an old-style value
 * adjustment must decide the mode first on its own path: it reads `inEffectLocked()` (a shared lock on the mode row, one
 * instant per transaction), or it is a private helper that every method calling it does so before the call. A second method in
 * the same file no longer gets past: the scan is on the syntax tree, per method. It sees a writer by the literal
 * `AdjustmentReason::X` in the `adjust()` call, so a reason held in a variable is not seen: keep it literal at the call.
 */

const OLD_STYLE_VALUE_REASONS = ['Cancellation', 'ItemCancellation', 'LateFee', 'Refund', 'Manual', 'Chargeback'];

/** The calls that read the mode under its lock. */
const MODE_LOCK_READS = ['inEffectLocked', 'enabledLocked'];

/**
 * @return array<string, array{writers: list<string>, gated: list<string>, callers: array<string, list<string>>}> per class file (relative to app/)
 */
function valueAdjustmentScan(string $root): array
{
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    $report = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $methods = $finder->findInstanceOf($parser->parse((string) file_get_contents($file->getPathname())) ?? [], Node\Stmt\ClassMethod::class);
        $entry = ['writers' => [], 'gated' => [], 'callers' => []];

        foreach ($methods as $method) {
            $calls = $finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class);

            foreach ($calls as $call) {
                if ($call->name instanceof Node\Identifier && $call->name->toString() === 'adjust') {
                    $reasons = $finder->find($call->args, fn (Node $node): bool => $node instanceof Node\Expr\ClassConstFetch
                        && $node->class instanceof Node\Name && str_ends_with($node->class->toString(), 'AdjustmentReason')
                        && $node->name instanceof Node\Identifier && in_array($node->name->toString(), OLD_STYLE_VALUE_REASONS, true));

                    if ($reasons !== []) {
                        $entry['writers'][] = $method->name->toString();
                    }
                }

                if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), MODE_LOCK_READS, true)) {
                    $entry['gated'][] = $method->name->toString();
                }

                if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this' && $call->name instanceof Node\Identifier) {
                    $entry['callers'][$call->name->toString()][] = $method->name->toString();
                }
            }
        }

        if ($entry['writers'] !== []) {
            $report[substr($file->getPathname(), strlen($root) + 1)] = ['writers' => array_values(array_unique($entry['writers'])), 'gated' => array_values(array_unique($entry['gated'])), 'callers' => $entry['callers']];
        }
    }

    ksort($report);

    return $report;
}

test('every method that writes an old-style value adjustment reads the mode under its lock, or is only called from one that does', function () {
    $scan = valueAdjustmentScan(dirname(__DIR__, 2).'/app');

    expect($scan)->not->toBeEmpty();

    foreach ($scan as $file => $entry) {
        foreach (array_unique($entry['writers']) as $writer) {
            if (in_array($writer, $entry['gated'], true)) {
                continue;
            }

            // A helper: every caller inside the class must read the mode itself, and it must have at least one caller there.
            $callers = array_unique($entry['callers'][$writer] ?? []);

            expect($callers)->not->toBeEmpty("{$file}::{$writer} writes a value adjustment without reading Nepal VAT mode under its lock");

            foreach ($callers as $caller) {
                expect(in_array($caller, $entry['gated'], true))->toBeTrue("{$file}::{$writer} is called from {$caller}, which does not read Nepal VAT mode under its lock");
            }
        }
    }
});

test('the scan sees an ungated writer', function () {
    $dir = sys_get_temp_dir().'/vat-arch-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Bad.php', '<?php class Bad { public function go($s, $i) { $s->adjust($i, K::Debit, AdjustmentReason::LateFee, 1); } public function ok($m, $s, $i) { $m->inEffectLocked(1); $s->adjust($i, K::Debit, AdjustmentReason::Refund, 1); } }');
    $scan = valueAdjustmentScan($dir);
    unlink($dir.'/Bad.php');
    rmdir($dir);

    expect($scan['Bad.php']['writers'])->toBe(['go', 'ok'])->and($scan['Bad.php']['gated'])->toBe(['ok']);
});

afterAll(fn () => releaseArchCache());
