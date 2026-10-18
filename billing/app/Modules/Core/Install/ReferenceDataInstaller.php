<?php

namespace App\Modules\Core\Install;

use App\Data\Install\ReferenceDataResult;
use App\Support\Install\ReferenceDataRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Runs every registered reference-data installer in order, each in its own transaction. Used by `app:install` and by
 * DatabaseSeeder, so the demo seed and a production install create the same reference rows.
 */
final class ReferenceDataInstaller
{
    public function __construct(private ReferenceDataRegistry $registry) {}

    /**
     * @return array<string, array{label: string, result: ReferenceDataResult}>
     */
    public function install(): array
    {
        $results = [];

        foreach ($this->registry->all() as $key => $installer) {
            $results[$key] = [
                'label' => $installer->label(),
                'result' => DB::transaction(fn (): ReferenceDataResult => $installer->install()),
            ];
        }

        return $results;
    }
}
