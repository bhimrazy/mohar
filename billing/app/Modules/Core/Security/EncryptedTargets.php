<?php

namespace App\Modules\Core\Security;

use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedFiles;
use App\Support\Security\EncryptedStorage;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Everything `security:reencrypt` covers: every column behind an Eloquent encrypted cast, found by loading each model
 * in app/Models and app/Modules/<Module>/Models (every module, loaded or not, since its tables may exist), plus what
 * modules register in EncryptedStorage. tests/Feature/Security/EncryptedStorageCoverageTest checks the source for
 * encrypted casts this discovery would miss.
 */
class EncryptedTargets
{
    private const CASTS = ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object', AsEncryptedArrayObject::class, AsEncryptedCollection::class];

    public function __construct(private EncryptedStorage $storage) {}

    /**
     * @return array<string, EncryptedColumn|EncryptedFiles> by name, sorted
     */
    public function all(): array
    {
        $targets = [];

        foreach ([...$this->castColumns(), ...array_values($this->storage->all())] as $target) {
            $targets[$target->name()] = $target;
        }

        ksort($targets);

        return $targets;
    }

    /**
     * @return list<EncryptedColumn>
     */
    public function castColumns(): array
    {
        $columns = [];

        foreach ($this->models() as $class) {
            try {
                $model = new $class;
            } catch (Throwable) {
                continue;
            }

            foreach ($model->getCasts() as $column => $cast) {
                if (in_array(explode(':', (string) $cast, 2)[0], self::CASTS, true) || in_array((string) $cast, self::CASTS, true)) {
                    $columns[] = new EncryptedColumn(
                        $model->getTable(),
                        (string) $column,
                        $model->getKeyName(),
                        intKey: in_array($model->getKeyType(), ['int', 'integer'], true),
                        connection: $model->getConnectionName(),
                    );
                }
            }
        }

        return $columns;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        $directories = array_filter(['Models', ...array_map(fn (string $path): string => 'Modules/'.basename(dirname($path)).'/Models', glob(app_path('Modules/*/Models'), GLOB_ONLYDIR) ?: [])], fn (string $relative): bool => is_dir(app_path($relative)));
        $models = [];

        foreach ($directories as $relative) {
            foreach ((new Finder)->files()->name('*.php')->in(app_path($relative))->sortByName() as $file) {
                $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relative.'/'.$file->getRelativePathname());

                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $models[] = $class;
                }
            }
        }

        return $models;
    }
}
