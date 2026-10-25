<?php

namespace App\Support\Reports;

use App\Contracts\Reports\HasSensitiveExport;
use App\Contracts\Reports\Report;
use App\Support\Permissions\PermissionRegistry;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The reports modules and packages register (ADR 0025 §1, pattern of ADR 0012), bound as a singleton in
 * AppServiceProvider so an owner registers from its own service provider whether or not any other module is loaded:
 *
 *   app(ReportRegistry::class)->register('vat_register', VatRegisterReport::class);
 *
 * register() builds nothing. It adds `reports.{key}.view` and `reports.{key}.export` to the PermissionRegistry
 * (group "Reports", labels resolved when the list is read), and the export is a sensitive permission when the class
 * implements HasSensitiveExport. get() builds the report through the container the first time and checks that its
 * key() is the registered key. A duplicate key throws unless `replace: true`, which logs.
 *
 * Sensitivity is a fact about the class, read at registration. A report registered by Closure cannot be inspected
 * before it is built, so it must not implement HasSensitiveExport (get() refuses it): register it by class name.
 *
 * @api
 */
final class ReportRegistry
{
    public const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, array{definition: class-string<Report>|Closure(Container): Report, sensitive: bool}> */
    private array $definitions = [];

    /** @var array<string, Report> */
    private array $resolved = [];

    public function __construct(private Container $container, private PermissionRegistry $permissions) {}

    /**
     * @param  class-string<Report>|Closure(Container): Report  $report
     */
    public function register(string $key, string|Closure $report, bool $replace = false): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid report key [{$key}]: use 1 to 50 lowercase letters, digits or underscores.");
        }

        if (is_string($report) && ! is_subclass_of($report, Report::class)) {
            throw new InvalidArgumentException("[{$report}] does not implement ".Report::class.'.');
        }

        if (isset($this->definitions[$key])) {
            if (! $replace) {
                throw new LogicException("A report is already registered for [{$key}]; pass replace: true to override it.");
            }

            Log::info('Report replaced.', ['report' => $key]);
            unset($this->resolved[$key]);
        }

        $sensitive = is_string($report) && is_subclass_of($report, HasSensitiveExport::class);
        $this->definitions[$key] = ['definition' => $report, 'sensitive' => $sensitive];

        $this->permissions->register('Reports', [
            "reports.{$key}.view" => fn (): string => __('View the report: :name', ['name' => $this->get($key)->label()]),
            "reports.{$key}.export" => fn (): string => __('Export the report: :name', ['name' => $this->get($key)->label()]),
        ], $sensitive ? ["reports.{$key}.export"] : []);
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->definitions);
        sort($keys);

        return $keys;
    }

    public function get(string $key): Report
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (! isset($this->definitions[$key])) {
            throw new InvalidArgumentException("No report is registered for [{$key}].");
        }

        ['definition' => $definition, 'sensitive' => $sensitive] = $this->definitions[$key];
        $report = $definition instanceof Closure ? $definition($this->container) : $this->container->make($definition);

        if (! $report instanceof Report) {
            throw new LogicException("The report registered as [{$key}] does not implement ".Report::class.'.');
        }

        if ($report->key() !== $key) {
            throw new LogicException("The report registered as [{$key}] reports its key as [{$report->key()}].");
        }

        if (($report instanceof HasSensitiveExport) !== $sensitive) {
            throw new LogicException("The report [{$key}] implements HasSensitiveExport but was registered by Closure; register it by class name so its permission is sensitive.");
        }

        return $this->resolved[$key] = $report;
    }
}
