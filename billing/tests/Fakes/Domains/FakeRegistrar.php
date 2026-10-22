<?php

namespace Tests\Fakes\Domains;

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Support\Domains\RegistrarDriverRegistry;
use Closure;
use Throwable;

/**
 * Scriptable registrar: set the next result (or exception) per command and inspect calls. Capabilities come from
 * interfaces (ADR 0012), so each resolve hands the host a FakeRegistrarDriver subclass that implements exactly the
 * capability interfaces named in $capabilities; a test can change them at any time.
 */
class FakeRegistrar
{
    /** @var list<string> every capability except `reconcile`, which tests opt into (it replaces the info check) */
    public array $capabilities = [];

    /** @var list<array{command: string, domain: string|null}> */
    public array $calls = [];

    /** @var array<string, mixed> command => result object, Throwable, or Closure returning either (runs during the call) */
    public array $results = [];

    public function __construct()
    {
        $this->capabilities = array_values(array_diff(array_keys(app(RegistrarDriverRegistry::class)->capabilities()), ['reconcile']));
    }

    public static function install(string $key = 'fake'): self
    {
        $fake = new self;
        app(RegistrarDriverRegistry::class)->register($key, fn (): RegistrarDriver => $fake->driver($key), replace: true);

        return $fake;
    }

    public function will(string $command, mixed $result): self
    {
        $this->results[$command] = $result;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->calls, 'command');
    }

    /**
     * A driver implementing the capability interfaces of $capabilities. PHP cannot implement interfaces at run time,
     * so the subclass (one per interface set, with an empty body) is declared once on first use.
     */
    public function driver(string $key): FakeRegistrarDriver
    {
        $all = app(RegistrarDriverRegistry::class)->capabilities();
        $interfaces = array_values(array_unique(array_intersect_key($all, array_flip($this->capabilities))));
        sort($interfaces);
        $class = 'FakeRegistrarDriver_'.md5(implode(',', $interfaces));

        if (! class_exists(__NAMESPACE__.'\\'.$class, false)) {
            $implements = $interfaces === [] ? '' : ' implements \\'.implode(', \\', $interfaces);
            eval('namespace '.__NAMESPACE__.'; final class '.$class.' extends FakeRegistrarDriver'.$implements.' {}');
        }

        /** @var class-string<FakeRegistrarDriver> $fqcn */
        $fqcn = __NAMESPACE__.'\\'.$class;

        return new $fqcn($this, $key);
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    public function record(string $command, ?string $domain, mixed $default): mixed
    {
        $this->calls[] = ['command' => $command, 'domain' => $domain];
        $result = $this->results[$command] ?? $default;

        if ($result instanceof Closure) {
            $result = $result();
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
