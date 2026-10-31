<?php

// Review board probes for PR #515 (step-up per query user). P1/P1b fail at a38f7dfe; the rest pass.

use App\Enums\ExportFormat;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Reports\DemoSensitiveReport;

function probeSetup(): array
{
    app(ReportRegistry::class)->register('demo_secret', DemoSensitiveReport::class);
    $owner = actingAsStaff(['reports.demo_secret.export']);
    $exporter = app(ReportExporter::class);

    return [$owner, $exporter, $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], $owner->id)];
}

function probeRequestFor(?User $user, ?int $confirmedAt): void
{
    $request = Request::create('/admin/reports/demo');
    $request->setLaravelSession(app('session.store'));
    if ($confirmedAt !== null) {
        $request->session()->put('auth.step_up_confirmed_at', $confirmedAt);
    }
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);
}

test('P1 a future-dated stored confirmation time is not a confirmation', function () {
    [$owner, $exporter, $query] = probeSetup();
    probeRequestFor(null, null);

    expect(RequireRecentPasswordConfirmation::confirmedFor($owner, time() + 10 * 365 * 86400))->toBeFalse();
});

test('P1b a future-dated session timestamp is not a confirmation either', function () {
    [$owner] = probeSetup();
    probeRequestFor($owner, time() + 86400);

    expect(RequireRecentPasswordConfirmation::recentlyConfirmed(request()))->toBeFalse();
});

test('P2 passwordless query user with a stored time is refused', function () {
    [$owner, $exporter, $query] = probeSetup();
    DB::table('users')->where('id', $owner->id)->update(['password' => null]);
    probeRequestFor(null, null);

    expect(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv, null, time()))->toThrow(HttpException::class);
});

test('P3 expired one-time password query user with a stored time is refused', function () {
    [$owner, $exporter, $query] = probeSetup();
    $owner->forceFill(['password_expires_at' => now()->subMinute()])->save();
    probeRequestFor(null, null);

    expect(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv, null, time()))->toThrow(HttpException::class);
});

test('P4 legit: same user, confirmed session, no stored time passes; window boundary equals middleware', function () {
    [$owner, $exporter, $query] = probeSetup();
    $t = RequireRecentPasswordConfirmation::timeout();
    probeRequestFor($owner, time() - $t + 5);
    expect($exporter->export('demo_secret', $query, ExportFormat::Csv))->toBeInstanceOf(StreamedResponse::class);

    probeRequestFor($owner, time() - $t);
    expect(RequireRecentPasswordConfirmation::recentlyConfirmed(request()))->toBeFalse()
        ->and(RequireRecentPasswordConfirmation::confirmedFor($owner, time() - $t))->toBeFalse()
        ->and(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv))->toThrow(HttpException::class);
});

test('P5 request user A (confirmed) cannot export as query user B (permitted, unconfirmed); nothing logged', function () {
    [$owner, $exporter, $query] = probeSetup();
    $attacker = actingAsStaff(['reports.demo_secret.export']);
    probeRequestFor($attacker, time());

    expect(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv))->toThrow(HttpException::class)
        ->and(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('P6 null query user and missing user are refused before step-up', function () {
    [$owner, $exporter] = probeSetup();
    $q = $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], null);
    $gone = $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], 999999);

    expect(fn () => $exporter->export('demo_secret', $q, ExportFormat::Csv, null, time()))->toThrow(AuthorizationException::class)
        ->and(fn () => $exporter->export('demo_secret', $gone, ExportFormat::Csv, null, time()))->toThrow(AuthorizationException::class);
});

test('P7 stored time zero / negative is refused', function () {
    [$owner, $exporter, $query] = probeSetup();
    probeRequestFor($owner, time());

    expect(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv, null, 0))->toThrow(HttpException::class)
        ->and(fn () => $exporter->export('demo_secret', $query, ExportFormat::Csv, null, -5))->toThrow(HttpException::class);
});
