<?php

namespace App\Data\Domains\Registrar;

use Carbon\CarbonImmutable;

final readonly class RegistrarResult
{
    /**
     * @param  'completed'|'pending'  $status
     * @param  array<string, mixed>  $meta  driver-specific flags (e.g. contact_change_pending, transfer_lock_days)
     * @param  array<string, string|int>  $remote  registrar ids later calls need (order id, transfer id), kept in
     *                                             registrar_meta.remote
     */
    public function __construct(
        public string $status = 'completed',
        public ?CarbonImmutable $expiryDate = null,
        public ?string $message = null,
        public array $meta = [],
        public array $remote = [],
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'expiry_date' => $this->expiryDate?->toDateString(), 'message' => $this->message, 'meta' => $this->meta, 'remote' => $this->remote];
    }

    /**
     * A copy with another message, so the host can replace driver text (for example with a masked version).
     */
    public function withMessage(string $message): static
    {
        return new self($this->status, $this->expiryDate, $message, $this->meta, $this->remote);
    }

    /**
     * A copy with the message and everything in `meta` passed through $mask: string values and keys (an integer key
     * as its digits), arrays walked. Whatever cannot be checked is replaced by $withheld: a branch nested deeper than $maxDepth, an object or
     * other non-scalar value, and a branch whose key the mask changed (its key becomes $withheld too). Numbers,
     * booleans and null are kept.
     *
     * `remote` is left as it is on purpose: its values are the registrar ids that later calls and the proof of
     * ownership depend on, and a mask would silently change one (`ORD-2026-0042` into `ORD-[redacted]-0042`).
     *
     * @param  \Closure(string): string  $mask
     */
    public function mapText(\Closure $mask, string $withheld, int $maxDepth = 8): static
    {
        $walk = function (array $values, int $depth) use (&$walk, $mask, $withheld, $maxDepth): array {
            $masked = [];

            foreach ($values as $key => $value) {
                $kept = match (true) {
                    is_string($value) => $mask($value),
                    is_array($value) => $depth >= $maxDepth ? $withheld : $walk($value, $depth + 1),
                    $value === null, is_scalar($value) => $value,
                    default => $withheld,
                };

                if ($mask((string) $key) !== (string) $key) {
                    $kept = $withheld;

                    for ($name = $withheld, $n = 2; array_key_exists($name, $masked) || array_key_exists($name, $values); $n++) {
                        $name = $withheld.' '.$n;
                    }

                    $key = $name;
                }

                $masked[$key] = $kept;
            }

            return $masked;
        };

        return new self($this->status, $this->expiryDate, $this->message === null ? null : $mask($this->message), $walk($this->meta, 1), $this->remote);
    }
}
