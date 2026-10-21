<?php

namespace App\Modules\Kyc\Models;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Database\Factories\KycDocumentFactory;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An identity document of a client. The images live encrypted on the objects disk under random paths; the number is
 * encrypted in the row. A replaced document is superseded and deleted by DocumentVault or the retention task.
 *
 * @property int $id
 * @property int $client_id
 * @property DocumentType $type
 * @property string $number decrypted on read
 * @property string $front_path
 * @property string $front_mime
 * @property string|null $back_path
 * @property string|null $back_mime
 * @property DocumentStatus $status
 * @property string|null $decline_reason
 * @property int|null $uploaded_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable|null $delete_after
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 */
#[UseFactory(KycDocumentFactory::class)]
#[Fillable(['client_id', 'type', 'number', 'front_path', 'front_mime', 'back_path', 'back_mime', 'status', 'uploaded_by', 'delete_after'])]
#[Hidden(['number', 'front_path', 'back_path'])]
class KycDocument extends Model
{
    /** @use HasFactory<KycDocumentFactory> */
    use HasFactory;

    public const DISK = 'objects';

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'number' => 'encrypted',
            'decided_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'delete_after' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Documents that are not replaced (uses kyc_documents_client_current_index with client_id).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /**
     * The last four characters of the number, for the client's own page.
     */
    public function maskedNumber(): string
    {
        $number = (string) $this->number;

        return mb_strlen($number) <= 4 ? '••••' : '••••'.mb_substr($number, -4);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_filter([$this->front_path, $this->back_path]));
    }
}
