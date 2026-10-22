<?php

namespace App\Modules\Core\Security;

use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use LogicException;
use Throwable;

/**
 * What `security:reencrypt` covers: the storage the loaded modules register in EncryptedStorage, with each registered
 * model expanded into its encrypted-cast columns. Targets are named `table.column` (`table.files` for file sets),
 * which is what operators pass to `--only` and what checkpoints are kept under.
 */
class EncryptedTargets
{
    private const CASTS = ['encrypted', AsEncryptedArrayObject::class, AsEncryptedCollection::class];

    public function __construct(private EncryptedStorage $storage) {}

    /**
     * @return array<string, EncryptedColumn|EncryptedFiles> by name, sorted
     *
     * @throws LogicException when a registered model cannot be built or has no encrypted cast, or two registrations
     *                        name the same storage
     */
    public function all(): array
    {
        $targets = [];

        foreach ($this->storage->all() as $key => $entry) {
            foreach ($entry instanceof EncryptedModel ? $this->columns($key, $entry) : [$entry] as $target) {
                if (isset($targets[$target->name()])) {
                    throw new LogicException("Encrypted storage [{$key}] covers {$target->name()}, which another registration already covers.");
                }

                $targets[$target->name()] = $target;
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * Whether a cast stores an encrypter payload.
     */
    public static function isEncryptedCast(mixed $cast): bool
    {
        return in_array(explode(':', (string) $cast, 2)[0], self::CASTS, true);
    }

    /**
     * @return list<EncryptedColumn>
     */
    private function columns(string $key, EncryptedModel $entry): array
    {
        try {
            $model = new ($entry->model);
        } catch (Throwable $exception) {
            throw new LogicException("Encrypted storage [{$key}]: the model {$entry->model} cannot be built ({$exception->getMessage()}).", previous: $exception);
        }

        $columns = [];

        foreach ($model->getCasts() as $column => $cast) {
            if (self::isEncryptedCast($cast)) {
                $columns[] = new EncryptedColumn(
                    $model->getTable(),
                    (string) $column,
                    $model->getKeyName(),
                    intKey: in_array($model->getKeyType(), ['int', 'integer'], true),
                    connection: $model->getConnectionName(),
                );
            }
        }

        if ($columns === []) {
            throw new LogicException("Encrypted storage [{$key}]: {$entry->model} has no encrypted cast.");
        }

        return $columns;
    }
}
