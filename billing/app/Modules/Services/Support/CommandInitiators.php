<?php

namespace App\Modules\Services\Support;

use App\Models\User;
use App\Modules\Services\Models\ModuleCommand;

/**
 * Who started a module command, in words: "System", the staff member's name, or "Client". The stored initiator is a
 * key (`staff:<id>`), never shown. prime() loads the names of a page of commands in one query; a name not primed is
 * looked up on its own, once per request.
 */
class CommandInitiators
{
    /** @var array<int, string|null> */
    private array $staffNames = [];

    /**
     * @param  iterable<ModuleCommand>  $commands
     */
    public function prime(iterable $commands): void
    {
        $ids = [];

        foreach ($commands as $command) {
            [$kind, $id] = array_pad(explode(':', $command->initiator, 2), 2, null);

            if ($kind === 'staff' && $id !== null && ctype_digit($id) && ! array_key_exists((int) $id, $this->staffNames)) {
                $ids[(int) $id] = null;
            }
        }

        if ($ids !== []) {
            $this->staffNames += User::query()->whereKey(array_keys($ids))->pluck('name', 'id')->all() + $ids;
        }
    }

    /**
     * Prime a page of commands and hand it back, for use inline in a resource collection.
     *
     * @template TPage of iterable<ModuleCommand>
     *
     * @param  TPage  $page
     * @return TPage
     */
    public function primed(iterable $page): iterable
    {
        $this->prime($page);

        return $page;
    }

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
