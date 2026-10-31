<?php

namespace App\Support\Billing;

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Integrations\ProvidesLogo;
use App\Data\Integrations\SettingField;
use ArrayObject;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Throwable;

/**
 * The conformance kit for a payment gateway driver (ADR 0009 Amendment (#515), ADR 0030 A5.6). A package calls
 * GatewayConformance::assertDriver('<key>') from its own tests once its provider has registered the driver. It checks
 * what Billing relies on without calling the provider: the registration, the declared capabilities against the methods
 * the driver implements, the settings fields and currencies, and a constructor that does no I/O. Each failure throws
 * GatewayConformanceFailure. tests/Feature/Billing/GatewayDriverRegistryTest.php runs it on every bundled driver.
 * For tests only: production code never calls it (tests/Arch/ExtensionsTest.php).
 *
 * @api
 */
final class GatewayConformance
{
    /** Flags a driver may declare (GatewayDriver constants; REDIRECT and the other ADR 0012 deprecated ones included). */
    private const FLAGS = [
        GatewayDriver::CAPTURE, GatewayDriver::TOKENIZE, GatewayDriver::REMOTE_INPUT, GatewayDriver::REFUND,
        GatewayDriver::PARTIAL_REFUND, GatewayDriver::WEBHOOK, GatewayDriver::PENDING_SETTLEMENT, GatewayDriver::REDIRECT,
        GatewayDriver::LOOKUP, GatewayDriver::QR, GatewayDriver::STORED_METHODS,
    ];

    /** A declared flag needs its method implemented, not AbstractGatewayDriver's refusing default. */
    private const METHODS = [
        GatewayDriver::CAPTURE => 'capture',
        GatewayDriver::REFUND => 'refund',
        GatewayDriver::TOKENIZE => 'tokenDetails',
        GatewayDriver::WEBHOOK => 'parseCallback',
    ];

    public static function assertDriver(string $key): void
    {
        $registry = app(GatewayDriverRegistry::class);

        self::that($registry->isRegistered($key), "No gateway driver is registered as [{$key}].");
        self::that(! in_array($key, GatewayDriverRegistry::RESERVED_KEYS, true), "The gateway key [{$key}] is reserved.");

        $driver = self::buildWithoutIo($registry, $key);

        self::that($registry->has($key), "The gateway driver [{$key}] is registered but unavailable: ".($registry->unavailable()[$key] ?? ''));
        self::that(trim($driver->name()) !== '', "The gateway driver [{$key}] has no name.");

        self::assertCapabilities($driver);
        self::assertSettings($driver);
        self::assertCurrencies($driver);

        if ($driver instanceof ProvidesLogo) {
            $logo = $driver->logo();
            self::that($logo === null || trim($logo->alt) !== '', "The logo of [{$key}] has no alt text.");
        }
    }

    private static function buildWithoutIo(GatewayDriverRegistry $registry, string $key): GatewayDriver
    {
        /** @var ArrayObject<int, string> $io */
        $io = new ArrayObject;
        $listening = new ArrayObject(['on' => false]);
        $events = app('events');
        // Put back exactly the listeners that were there, so repeated checks do not pile up listeners.
        $before = $events instanceof Dispatcher ? array_intersect_key($events->getRawListeners(), array_flip([QueryExecuted::class, RequestSending::class])) : null;
        DB::listen(function (QueryExecuted $query) use ($io, $listening): void {
            if ($listening['on']) {
                $io[] = 'query: '.mb_substr($query->sql, 0, 120);
            }
        });
        Event::listen(RequestSending::class, function (RequestSending $event) use ($io, $listening): void {
            if ($listening['on']) {
                $io[] = 'HTTP: '.$event->request->url();
            }
        });

        try {
            // A driver bound as a singleton is built once; forget it so the build is the one observed.
            app()->forgetInstance($registry->get($key)::class);
            $listening['on'] = true;
            $driver = $registry->get($key);
        } catch (Throwable $exception) {
            throw new GatewayConformanceFailure("The gateway driver [{$key}] cannot be built: {$exception->getMessage()}", 0, $exception);
        } finally {
            $listening['on'] = false;

            if ($before !== null && $events instanceof Dispatcher) {
                foreach ([QueryExecuted::class, RequestSending::class] as $event) {
                    $events->forget($event);

                    foreach ($before[$event] ?? [] as $listener) {
                        $events->listen($event, $listener);
                    }
                }
            }
        }

        self::that(count($io) === 0, "Building the gateway driver [{$key}] did I/O (ADR 0012: constructors run no queries or HTTP): ".implode('; ', $io->getArrayCopy()));

        return $driver;
    }

    private static function assertCapabilities(GatewayDriver $driver): void
    {
        $key = $driver->key();
        $declared = self::listOf($driver->capabilities(), fn (mixed $flag): bool => in_array($flag, self::FLAGS, true));

        self::that($declared !== null, "capabilities() of [{$key}] must be a list of GatewayDriver capability constants.");

        foreach (self::FLAGS as $flag) {
            self::that($driver->supports($flag) === in_array($flag, $declared, true), "supports('{$flag}') of [{$key}] disagrees with capabilities().");
        }

        foreach (self::METHODS as $flag => $method) {
            if ($driver->supports($flag)) {
                $owner = (new ReflectionMethod($driver, $method))->getDeclaringClass()->getName();
                self::that($owner !== AbstractGatewayDriver::class, "The gateway driver [{$key}] declares [{$flag}] but does not implement {$method}().");
            }
        }

        self::that(! $driver->supports(GatewayDriver::PARTIAL_REFUND) || $driver->supports(GatewayDriver::REFUND), "The gateway driver [{$key}] declares partial refunds without refunds.");
        // ADR 0012: until the deprecated flag is removed it must agree with the interface that replaced it.
        self::that($driver->supports(GatewayDriver::REDIRECT) === $driver instanceof HostedPaymentDriver, "REDIRECT of [{$key}] disagrees with HostedPaymentDriver.");
    }

    private static function assertSettings(GatewayDriver $driver): void
    {
        $key = $driver->key();
        $fields = self::listOf($driver->settingsFields(), fn (mixed $field): bool => $field instanceof SettingField);

        self::that($fields !== null, "settingsFields() of [{$key}] must return a list of SettingField objects, not the deprecated array shape.");

        $keys = array_map(fn (SettingField $field): string => $field->key, $fields ?? []);
        self::that(count($keys) === count(array_unique($keys)), "The gateway driver [{$key}] declares a setting key twice.");
    }

    private static function assertCurrencies(GatewayDriver $driver): void
    {
        $key = $driver->key();
        $currencies = $driver->currencies();

        self::that(! $driver instanceof HostedPaymentDriver || $currencies !== null, "The hosted gateway driver [{$key}] must name the currencies it settles in.");

        if ($currencies !== null) {
            $codes = self::listOf($currencies, fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1);
            self::that($codes !== null && $codes !== [], "currencies() of [{$key}] must be null (any) or a non-empty list of upper-case ISO 4217 codes.");
        }
    }

    /**
     * $values when it is a list whose every item passes $test, else null. Drivers may come from packages, so what
     * their docblocks promise is checked, not assumed.
     *
     * @param  Closure(mixed): bool  $test
     * @return list<mixed>|null
     */
    private static function listOf(mixed $values, Closure $test): ?array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return null;
        }

        foreach ($values as $value) {
            if (! $test($value)) {
                return null;
            }
        }

        return $values;
    }

    private static function that(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new GatewayConformanceFailure($message);
        }
    }
}
