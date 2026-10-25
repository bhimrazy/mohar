<?php

use App\Modules\Billing\Support\NoteAllocator;
use Tests\TestCase;

uses(TestCase::class);

/*
| The credit note allocation (vat-register §5.1.2 and §5.1.3) on plain lines: whatever the sequence of notes, a line is
| never credited more net or tax than it holds, nothing goes negative, and once fully credited the notes sum to the line.
*/

/**
 * @return array{id: int, description: string, taxable: bool, gross: int, net: int, tax1: int, tax2: int, used_gross: int, used_net: int, used_tax1: int, used_tax2: int}
 */
function allocLine(int $id, int $net, int $tax1, int $tax2 = 0): array
{
    return ['id' => $id, 'description' => "Line $id", 'taxable' => $tax1 + $tax2 > 0, 'gross' => $net + $tax1 + $tax2, 'net' => $net, 'tax1' => $tax1, 'tax2' => $tax2,
        'used_gross' => 0, 'used_net' => 0, 'used_tax1' => 0, 'used_tax2' => 0];
}

/** @param  array<string, mixed>  $line */
function allocUse(array $line, array $part): array
{
    foreach (['net', 'tax1', 'tax2'] as $key) {
        $line["used_$key"] += $part[$key];
    }
    $line['used_gross'] += $part['gross'];

    return $line;
}

/** Every part is inside what the line had left, and nothing is negative. */
function allocWithinLine(array $line, array $part): bool
{
    return $part['net'] >= 0 && $part['tax1'] >= 0 && $part['tax2'] >= 0
        && $part['gross'] === $part['net'] + $part['tax1'] + $part['tax2']
        && $part['net'] <= $line['net'] - $line['used_net'] && $part['tax1'] <= $line['tax1'] - $line['used_tax1'] && $part['tax2'] <= $line['tax2'] - $line['used_tax2'];
}

test('many small credits on one line never take more net than it has, and the remainder is exact', function (int $small, int $count) {
    $allocator = new NoteAllocator;
    $line = allocLine(1, 100, 13);

    for ($i = 0; $i < $count; $i++) {
        $part = $allocator->byLines([$line], [1 => $small], 'NPR')[0];
        expect(allocWithinLine($line, $part))->toBeTrue("note $i");
        $line = allocUse($line, $part);
    }

    $rest = $allocator->byLines([$line], [1 => 113 - $line['used_gross']], 'NPR')[0];

    expect(allocWithinLine($line, $rest))->toBeTrue()
        ->and([$line['used_net'] + $rest['net'], $line['used_tax1'] + $rest['tax1']])->toBe([100, 13]);
})->with([
    '26 notes of 4' => [4, 26],
    '28 notes of 4' => [4, 28],
    '100 notes of 1' => [1, 100],
]);

test('random note sequences by line and by amount stay inside every line and sum to it when fully credited', function (int $seed) {
    mt_srand($seed);
    $allocator = new NoteAllocator;
    $originals = [allocLine(1, mt_rand(1, 5_000), mt_rand(0, 700), mt_rand(0, 200)), allocLine(2, mt_rand(1, 5_000), 0, 0), allocLine(3, mt_rand(1, 400), mt_rand(0, 60))];
    $lines = $originals;
    $total = array_sum(array_column($originals, 'gross'));
    $credited = 0;

    while ($credited < $total) {
        $left = $total - $credited;
        $gross = mt_rand(1, max(1, intdiv($left, mt_rand(1, 6))));
        $open = collect($lines)->filter(fn (array $line): bool => $line['gross'] > $line['used_gross'])->random();
        $parts = mt_rand(0, 1) === 1
            ? $allocator->byAmount($lines, $gross, 'NPR')
            : $allocator->byLines($lines, [(int) $open['id'] => min($open['gross'] - $open['used_gross'], 1 + mt_rand(0, 50))], 'NPR');

        foreach ($parts as $part) {
            $index = $part['item_id'] - 1;
            expect(allocWithinLine($lines[$index], $part))->toBeTrue("seed $seed");
            $lines[$index] = allocUse($lines[$index], $part);
            $credited += $part['gross'];
        }
    }

    foreach ($originals as $i => $original) {
        expect([$lines[$i]['used_net'], $lines[$i]['used_tax1'], $lines[$i]['used_tax2']])->toBe([$original['net'], $original['tax1'], $original['tax2']]);
    }
})->with(range(1, 60));

test('a spread by amount never allocates more than asked, even when a line is already over-used', function () {
    $line = fn (int $id, int $used) => ['used_gross' => $used] + allocLine($id, 1_000, 130);

    $parts = (new NoteAllocator)->byAmount([$line(1, 1_500), $line(2, 0), $line(3, 0)], 1_000, 'NPR');

    expect(array_sum(array_column($parts, 'gross')))->toBe(1_000)->and(array_column($parts, 'item_id'))->not->toContain(1);
});

test('a line whose gross is not net plus tax is refused before anything is allocated (#396 N2)', function (array $line) {
    expect(fn () => (new NoteAllocator)->byLines([$line], [1 => 100], 'NPR'))->toThrow(InvalidArgumentException::class, 'gross must equal net plus tax');
})->with([
    'its value' => [['gross' => 11_301] + allocLine(1, 10_000, 1_300)],
    'what is used' => [['used_gross' => 1_000, 'used_net' => 1_000, 'used_tax1' => 130] + allocLine(1, 10_000, 1_300)],
]);
