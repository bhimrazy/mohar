<?php

namespace App\Support\Extensions;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Str;

/**
 * Base provider for a bundled extension, app/Extensions/<Family>/<Name>/<Name>ServiceProvider (ADR 0030 §2), loaded
 * by App\Providers\ExtensionsServiceProvider after every module. It keeps the module conventions of
 * ModuleServiceProvider (migrations, routes, Resources/views and lang, Console/Commands, schedule()) with one
 * difference: the config file is Config/<last segment of configKey()>.php and merges into configKey(), so an extension
 * can own one subtree of a shared key (Khalti's Config/khalti.php is `payments_nepal.khalti`).
 *
 * @api
 */
abstract class ExtensionServiceProvider extends ModuleServiceProvider
{
    /**
     * The config key the extension's settings live under, e.g. "payments_stripe" or "payments_nepal.khalti".
     */
    abstract public function configKey(): string;

    /**
     * The view namespace: configKey() with dots as underscores.
     */
    final public function moduleKey(): string
    {
        return str_replace('.', '_', $this->configKey());
    }

    public function register(): void
    {
        $config = $this->modulePath('Config/'.Str::afterLast($this->configKey(), '.').'.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, $this->configKey());
        }

        $this->registerExtension();
    }

    /**
     * Container bindings.
     */
    protected function registerExtension(): void {}

    /**
     * Driver and listener registrations; called once, at the end of boot().
     */
    protected function bootExtension(): void {}

    final protected function registerModule(): void {}

    final protected function bootModule(): void
    {
        $this->bootExtension();
    }
}
