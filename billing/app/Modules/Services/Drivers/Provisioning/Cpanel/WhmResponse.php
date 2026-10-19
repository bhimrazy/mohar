<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

/**
 * A parsed WHM API 1 answer: `metadata.result` (1 success, 0 refused), its free-text `metadata.reason`, and `data`.
 */
final readonly class WhmResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $function,
        public bool $ok,
        public string $reason,
        public array $data,
        public array $metadata,
    ) {}

    /**
     * The records under `data.<key>` (WHM uses `acct`, `pkg`, ...), always as a list of arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        $rows = $this->data[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
