<?php

namespace App\Data\Migration;

use App\Contracts\Migration\ImportIdResolver;
use Carbon\CarbonImmutable;

/**
 * The run an importer works for: which source, when, by whom, and the run's options.
 */
final readonly class ImportContext
{
    /**
     * @param  array<string, scalar|null>  $options
     */
    public function __construct(
        public int $runId,
        public int $sourceConnectionId,
        public string $sourceSystem,
        public bool $dryRun,
        public CarbonImmutable $importedAt,
        public ?CarbonImmutable $cutoverAt,
        public ImportIdResolver $ids,
        public array $options = [],
        public ?int $actorId = null,
    ) {}
}
