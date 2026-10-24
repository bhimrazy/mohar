<?php

namespace App\Modules\Services\Support;

use App\Models\User;

/**
 * Who started a module command, in words: "System", the staff member's name, or "Client". The stored initiator is a
 * key (`staff:<id>`), never shown. Names are looked up once per staff member per request.
 */
class CommandInitiators
{
    /** @var array<int, string|null> */
    private array $staffNames = [];

    public function label(string $initiator): string
    {
        [$kind, $id] = array_pad(explode(':', $initiator, 2), 2, null);

        return match ($kind) {
            'system' => __('System'),
            'client' => __('Client'),
            'staff' => ($id !== null && ctype_digit($id) ? $this->staffName((int) $id) : null) ?? __('Staff'),
            default => ucfirst(str_replace('_', ' ', $initiator)),
        };
    }

    private function staffName(int $id): ?string
    {
        if (! array_key_exists($id, $this->staffNames)) {
            $this->staffNames[$id] = User::query()->whereKey($id)->value('name');
        }

        return $this->staffNames[$id];
    }
}
