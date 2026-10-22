<?php

namespace App\Modules\Kyc\Database\Factories;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KycDocument>
 */
class KycDocumentFactory extends Factory
{
    protected $model = KycDocument::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type' => DocumentType::Citizenship,
            'number' => (string) fake()->numerify('##-##-##-#####'),
            'front_path' => 'kyc/test/'.Str::uuid()->toString(),
            'front_mime' => 'image/png',
            'back_path' => 'kyc/test/'.Str::uuid()->toString(),
            'back_mime' => 'image/png',
            'status' => DocumentStatus::Pending,
        ];
    }

    public function type(DocumentType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function approved(): static
    {
        return $this->state(['status' => DocumentStatus::Approved]);
    }

    public function declined(string $reason = 'The photo is blurred.'): static
    {
        return $this->state(['status' => DocumentStatus::Declined, 'decline_reason' => $reason]);
    }
}
