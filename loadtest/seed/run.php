<?php

declare(strict_types=1);

/*
 * Load-test seed data (loadtest/README.md). Boots the app in --app (default /app, the image's code) and bulk-inserts
 * clients, services, a year of invoices, payments and tickets, then checks the invariants (Verifier).
 *
 *   LOADTEST_PASSWORD=... php loadtest/seed/run.php --app=billing --volume=tiny     local check on SQLite
 *   LOADTEST_PASSWORD=... php /loadtest/seed/run.php --volume=large                 in the load-test stack
 *   php loadtest/seed/run.php --app=billing --verify                                checks only
 *   php loadtest/seed/run.php --app=billing --self-test                             date arithmetic vs BillingCycle
 *
 * Options: --volume=tiny|small|medium|large, --clients=N and --services=M override the preset, --seed=N (default
 * 2026), --peak=PCT (share of recurring services renewing on today's billing run, default 25), --json=path (write
 * the summary there too). Refused when APP_ENV is production: it creates logins with a shared, known password.
 */

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use LoadTest\Seed\Calendar;
use LoadTest\Seed\LoadTestSeeder;
use LoadTest\Seed\Verifier;
use LoadTest\Seed\Volume;

$options = getopt('', ['app:', 'volume:', 'clients:', 'services:', 'seed:', 'peak:', 'json:', 'verify', 'self-test', 'help']);

if (isset($options['help'])) {
    preg_match('#/\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $header);
    fwrite(STDOUT, trim((string) preg_replace('/^ \* ?/m', '', $header[1] ?? '')).PHP_EOL);
    exit(0);
}

$root = rtrim((string) ($options['app'] ?? '/app'), '/');

if (! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "No Laravel app with installed dependencies at {$root} (pass --app=<path to billing>).\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->isProduction()) {
    fwrite(STDERR, "Refused: APP_ENV is production. Load-test data creates logins with a shared password; run it with APP_ENV=loadtest\n"
        ."against a throwaway database (loadtest/README.md).\n");
    exit(2);
}

foreach (['Calendar', 'BulkWriter', 'Volume', 'LoadTestSeeder', 'Verifier'] as $class) {
    require __DIR__."/{$class}.php";
}

ini_set('memory_limit', '1536M'); // within the seeder container's 2 GB limit (compose.loadtest.yaml)
$log = function (string $line): void {
    static $started = null;
    $started ??= hrtime(true);
    fwrite(STDERR, sprintf("[%6.1fs] %s\n", (hrtime(true) - $started) / 1e9, $line));
};

if (isset($options['self-test'])) {
    // Calendar::advanceMonths must equal BillingCycle::advance for every anchor day across month ends and leap years.
    $failures = 0;

    for ($day = Calendar::toDays(2023, 1, 1); $day <= Calendar::toDays(2029, 12, 31); $day += 3) {
        foreach ([1, 3, 12, 36, -1] as $months) {
            foreach ([null, 1, 15, 28, 29, 30, 31] as $anchor) {
                $carbon = BillingCycle::Monthly->advance(CarbonImmutable::parse(Calendar::date($day)), $months, $anchor)->toDateString();
                $failures += Calendar::date(Calendar::advanceMonths($day, $months, $anchor)) === $carbon ? 0 : 1;
            }
        }
    }

    $failures += Calendar::date(Calendar::today()) === gmdate('Y-m-d') ? 0 : 1;
    fwrite(STDOUT, json_encode(['calendar_mismatches' => $failures]).PHP_EOL);
    exit($failures === 0 ? 0 : 1);
}

$summary = ['database' => config('database.default'), 'date' => CarbonImmutable::today()->toDateString()];

if (! isset($options['verify'])) {
    $password = (string) getenv('LOADTEST_PASSWORD');

    if (strlen($password) < 12) {
        fwrite(STDERR, "Set LOADTEST_PASSWORD (12+ characters): every seeded login (staff and clients) uses it.\n");
        exit(2);
    }

    $volume = Volume::named(
        (string) ($options['volume'] ?? 'small'),
        isset($options['clients']) ? (int) $options['clients'] : null,
        isset($options['services']) ? (int) $options['services'] : null,
    );
    $log("seeding volume {$volume->name}: {$volume->clients} clients, {$volume->services} services ({$summary['database']})");
    $seeder = new LoadTestSeeder($volume, $password, (int) ($options['seed'] ?? 2026), max(0, min(100, (int) ($options['peak'] ?? 25))), $log);

    try {
        $summary = [...$summary, 'volume' => $volume->name, ...$seeder->run()];
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Seeding failed: '.$exception->getMessage().' ('.basename($exception->getFile()).':'.$exception->getLine().")\n");
        exit(1);
    }
}

$log('verifying invariants');
$started = hrtime(true);
$summary['invariants'] = (new Verifier)->run();
$summary['verify_seconds'] = round((hrtime(true) - $started) / 1e9, 1);
$broken = array_sum($summary['invariants']);
$json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
fwrite(STDOUT, $json.PHP_EOL);

if (isset($options['json'])) {
    file_put_contents((string) $options['json'], $json.PHP_EOL);
}

$log($broken === 0 ? 'all invariants hold' : "{$broken} invariant violation(s)");
exit($broken === 0 ? 0 : 1);
