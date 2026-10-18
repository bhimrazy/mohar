<?php

namespace Tests\Fakes\Platform;

use App\Contracts\Currencies\PriceConverter;
use App\Data\Currencies\PriceChange;
use App\Data\Currencies\PriceConversionPreview;

/**
 * Price converter for Platform tests: two pending changes until apply() runs.
 */
final class FakePriceConverter implements PriceConverter
{
    public int $applied = 0;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Test prices';
    }

    public function automatic(): bool
    {
        return false;
    }

    /** @var list<string> */
    public array $currenciesInUse = [];

    public function usesCurrency(string $code): bool
    {
        return in_array($code, $this->currenciesInUse, true);
    }

    public function preview(int $samples = 10): PriceConversionPreview
    {
        $changes = $this->applied > 0 ? [] : [new PriceChange('Starter', 'NPR', 'Monthly', 130_000, 134_266)];

        return new PriceConversionPreview('fake', 'Test prices', count($changes) * 2, 5, 1, $changes);
    }

    public function apply(): int
    {
        $this->applied++;

        return 2;
    }
}
