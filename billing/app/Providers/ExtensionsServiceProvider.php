<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Discovers and registers every bundled extension, app/Extensions/<Family>/<Name>/<Name>ServiceProvider (ADR 0030 §2).
 * Listed after ModulesServiceProvider in bootstrap/providers.php, so extensions register and boot after every module.
 *
 * MODULES (a test aid, ADR 0030 A5.5): empty loads every extension; otherwise only the extensions it names as
 * "<Family>/<Name>" (MODULES=Billing,Gateways/Stripe), so MODULES=Core loads none.
 *
 * The order depends only on the files on disk, never on a cache, so a request, a queue worker and `config:cache` or
 * `route:cache` see the same order: the extensions in ORDER first, as listed, then the rest by path.
 */
class ExtensionsServiceProvider extends ServiceProvider
{
    /**
     * The gateways' registration order before ADR 0030 M1 (module order, Khalti before eSewa before connectIPS inside
     * PaymentsNepal). A new gateway's default `gateways.sort_order` is its position in GatewayDriverRegistry, so this
     * keeps the order of payment pickers on a fresh install.
     */
    private const ORDER = [
        'Gateways/Cybersource', 'Gateways/Khalti', 'Gateways/Esewa', 'Gateways/Connectips', 'Gateways/Razorpay', 'Gateways/Stripe',
    ];

    public function register(): void
    {
        foreach (self::enabledExtensions() as $extension) {
            $this->app->register(self::providerClass($extension));
        }
    }

    /**
     * Every extension on disk that has its provider, as "<Family>/<Name>", in load order.
     *
     * @return list<string>
     */
    public static function availableExtensions(): array
    {
        $found = [];

        foreach (glob(app_path('Extensions/*/*'), GLOB_ONLYDIR) ?: [] as $path) {
            $extension = basename(dirname($path)).'/'.basename($path);

            if (class_exists(self::providerClass($extension))) {
                $found[] = $extension;
            }
        }

        sort($found);
        $first = array_values(array_intersect(self::ORDER, $found));

        return [...$first, ...array_values(array_diff($found, $first))];
    }

    /**
     * @return list<string>
     */
    public static function enabledExtensions(): array
    {
        $only = array_filter(array_map('trim', explode(',', (string) config('modules.only', ''))));

        if ($only === []) {
            return self::availableExtensions();
        }

        return array_values(array_filter(self::availableExtensions(), fn (string $extension): bool => in_array($extension, $only, true)));
    }

    /**
     * @return class-string
     */
    public static function providerClass(string $extension): string
    {
        [$family, $name] = explode('/', $extension, 2);

        return "App\\Extensions\\{$family}\\{$name}\\{$name}ServiceProvider";
    }
}
