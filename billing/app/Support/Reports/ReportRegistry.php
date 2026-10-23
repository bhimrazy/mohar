<?php

namespace App\Support\Reports;

use App\Contracts\Reports\ConfiguresExport;
use App\Contracts\Reports\Report;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * The reports modules and packages register (ADR 0025 §1), bound as a singleton in AppServiceProvider so an owner
 * registers from its own service provider whether or not any other module is loaded:
 *
 *   app(ReportRegistry::class)->register(VatRegisterReport::class);
 *
 * register() builds the report through the container, checks its key, and adds `reports.{key}.view` and
 * `reports.{key}.export` to the PermissionRegistry (group "Reports"); the export is a sensitive permission when the
 * report's export is sensitive. A duplicate key fails at boot.
 *
 * @api
 */
final class ReportRegistry
{
    public const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /** @var array<string, Report> */
    private array $reports = [];

    public function __construct(private Container $container, private PermissionRegistry $permissions) {}

    /**
     * @param  class-string<Report>  $report
     */
    public function register(string $report): void
    {
        $instance = $this->container->make($report);

        if (! $instance instanceof Report) {
            throw new InvalidArgumentException("[{$report}] does not implement ".Report::class.'.');
        }

        $key = $instance->key();

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid report key [{$key}]: use 1 to 50 lowercase letters, digits or underscores.");
        }

        if (isset($this->reports[$key])) {
            throw new LogicException("A report with the key [{$key}] is already registered by [".$this->reports[$key]::class.'].');
        }

        $this->reports[$key] = $instance;
        $sensitive = $instance instanceof ConfiguresExport && $instance->sensitiveExport();

        $this->permissions->register('Reports', [
            "reports.{$key}.view" => 'View the report: '.$instance->label(),
            "reports.{$key}.export" => 'Export the report: '.$instance->label(),
        ], $sensitive ? ["reports.{$key}.export"] : []);
    }

    public function has(string $key): bool
    {
        return isset($this->reports[$key]);
    }

    public function get(string $key): Report
    {
        return $this->reports[$key] ?? throw new InvalidArgumentException("No report is registered for [{$key}].");
    }

    /**
     * @return array<string, Report>
     */
    public function all(): array
    {
        ksort($this->reports);

        return $this->reports;
    }
}
