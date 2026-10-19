<?php

namespace Mohar\Simulators\Kernel;

use App\Support\Integrations\Redactor;

/**
 * Recordings of a provider's responses (`tools/simulators/<provider>/recordings/<operation>.json`), tagged `source:
 * live` (from a real sandbox, `simulate:record`) or `source: docs` (written from the reference), and the shape check
 * that compares what a simulator serves with them: key paths and types.
 */
final class Recorder
{
    public function __construct(private readonly string $directory, private readonly Redactor $redactor = new Redactor) {}

    /**
     * @param  array<array-key, mixed>  $response
     * @param  list<string>  $secrets  values to mask wherever they appear (the credentials used)
     */
    public function record(string $operation, array $response, string $source, array $secrets = []): string
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        $path = $this->directory.'/'.preg_replace('/[^a-z0-9_.-]/i', '_', $operation).'.json';
        file_put_contents($path, json_encode([
            'source' => $source,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'response' => $this->redactor->redact($response, $secrets),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }

    /**
     * Every key path with its type: `data.acct.*.user` => `string`. List items share one path (`*`).
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, string>
     */
    public static function shape(array $value, string $prefix = ''): array
    {
        $shape = [];

        foreach ($value as $key => $item) {
            $path = ltrim($prefix.'.'.(array_is_list($value) ? '*' : $key), '.');
            $shape[$path] = is_array($item) ? (array_is_list($item) ? 'list' : 'object') : get_debug_type($item);

            if (is_array($item)) {
                $shape = [...$shape, ...self::shape($item, $path)];
            }
        }

        ksort($shape);

        return $shape;
    }

    /**
     * Where the actual response differs from the recording: missing paths, extra paths and changed types.
     *
     * @param  array<array-key, mixed>  $recorded
     * @param  array<array-key, mixed>  $actual
     * @return list<string>
     */
    public static function differences(array $recorded, array $actual): array
    {
        $expected = self::shape($recorded);
        $found = self::shape($actual);
        $differences = [];

        foreach ($expected as $path => $type) {
            if (! isset($found[$path])) {
                $differences[] = "missing {$path}";
            } elseif ($found[$path] !== $type && $type !== 'null' && $found[$path] !== 'null') {
                $differences[] = "{$path} is {$found[$path]}, recorded {$type}";
            }
        }

        foreach (array_diff_key($found, $expected) as $path => $type) {
            $differences[] = "extra {$path}";
        }

        return $differences;
    }
}
