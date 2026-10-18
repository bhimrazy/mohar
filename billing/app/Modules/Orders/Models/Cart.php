<?php

namespace App\Modules\Orders\Models;

use App\Modules\Core\Models\Client;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Database\Factories\CartFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $token
 * @property int|null $client_id
 * @property string $currency
 * @property string|null $promotion_code
 * @property int $schema_version
 * @property list<array<string, mixed>> $items
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(CartFactory::class)]
#[Fillable(['token', 'client_id', 'currency', 'promotion_code', 'items', 'expires_at'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $attributes = [
        'items' => '[]',
        'schema_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'expires_at' => 'datetime',
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
     * @return list<CartItem>
     */
    public function cartItems(): array
    {
        return array_map(fn (array $item): CartItem => CartItem::fromArray($item), $this->items);
    }

    /**
     * @param  list<CartItem>  $items
     */
    public function setCartItems(array $items): void
    {
        $this->items = array_map(fn (CartItem $item): array => $item->toArray(), $items);
    }

    public function findItem(string $key): ?CartItem
    {
        foreach ($this->cartItems() as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
