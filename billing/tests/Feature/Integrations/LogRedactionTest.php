<?php

/*
 * The log backstop of ADR 0023 step 4: RedactingProcessor on every channel's handlers (RedactLogs), RedactionScope
 * and the queue.failer decorator.
 */

use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;
use App\Support\Logging\RedactingFailedJobProvider;
use App\Support\Logging\RedactingProcessor;
use App\Support\Logging\RedactLogs;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Tests\Support\Redaction\RedactorTiming;

/**
 * A channel built like the configured ones (PsrLogMessageProcessor on the logger, the tap on the handler) that keeps
 * its records.
 */
function logRedactionChannel(): TestHandler
{
    config(['logging.channels.redaction_probe' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'processors' => [PsrLogMessageProcessor::class],
        'tap' => [RedactLogs::class],
    ]]);

    return Log::channel('redaction_probe')->getLogger()->getHandlers()[0];
}

test('the processor runs last on the handlers of the channels production and cPanel use', function (string $channel) {
    foreach (Log::channel($channel)->getLogger()->getHandlers() as $handler) {
        $processors = [];

        try {
            while (true) {
                $processors[] = $handler->popProcessor();
            }
        } catch (LogicException) {
        }

        expect(end($processors))->toBeInstanceOf(RedactingProcessor::class);
    }
})->with(['stderr', 'single', 'daily']);

test('placeholders are filled in before the record is masked', function () {
    $handler = logRedactionChannel();

    Log::channel('redaction_probe')->warning('Provider said {answer}', ['answer' => 'password=LOGORDER1']);

    expect($handler->getRecords()[0]->message)->toBe('Provider said password=[redacted]');
});

test('context, its keys and extra are masked by name and shape', function () {
    $handler = logRedactionChannel();
    Context::add('api_key', 'LOGEXTRA1');

    Log::channel('redaction_probe')->info('Sync failed', ['password' => 'LOGCTX1', 'answer' => ['body' => 'token=LOGCTX2'], 'id' => 7]);

    $record = $handler->getRecords()[0];
    expect(json_encode([$record->context, $record->extra]))->not->toContain('LOGCTX1')->not->toContain('LOGCTX2')->not->toContain('LOGEXTRA1')
        ->and($record->context['id'])->toBe(7);
});

test('a line written during a scoped call is masked by value, and only while the call runs', function () {
    $handler = logRedactionChannel();
    $set = RedactionSet::of(['LogScopeValue42']);

    RedactionScope::during($set, fn () => Log::channel('redaction_probe')->info('Answer was LogScopeValue42'));
    Log::channel('redaction_probe')->info('Answer was LogScopeValue42');

    expect($handler->getRecords()[0]->message)->toBe('Answer was [redacted]')
        ->and($handler->getRecords()[1]->message)->toBe('Answer was LogScopeValue42');
});

test('an exception that left a scoped call keeps its set for a later report, nested sets merged', function () {
    $handler = logRedactionChannel();

    try {
        RedactionScope::during(RedactionSet::of(['OuterValue0042']), fn () => RedactionScope::during(
            RedactionSet::of(['InnerValue0042']),
            fn () => throw new RuntimeException('Login failed', previous: new LogicException('sent OuterValue0042 and InnerValue0042')),
        ));
    } catch (RuntimeException $exception) {
        Log::channel('redaction_probe')->error('Driver call failed', ['exception' => $exception]);
    }

    $record = $handler->getRecords()[0];
    expect($record->context['exception'])->toBe($exception)
        ->and($exception->getPrevious()->getMessage())->toBe('sent [redacted] and [redacted]')
        ->and(RedactionScope::current()->isEmpty())->toBeTrue();
});

test('a record is withheld when masking fails or a context object logs while it is masked', function () {
    $handler = logRedactionChannel();
    $throws = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('cannot serialise');
        }
    };
    $logs = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            Log::channel('redaction_probe')->info('inner password=LOGINNER1');

            return ['ok' => true];
        }
    };

    Log::channel('redaction_probe')->info('outer token=LOGOUTER1', ['object' => $throws]);
    Log::channel('redaction_probe')->info('outer two', ['object' => $logs]);

    [$failed, $inner, $outer] = $handler->getRecords();
    expect([$failed->message, $failed->context, $failed->extra])->toBe([Redactor::MASK, [], ['redaction_failed' => true]])
        ->and([$inner->message, $inner->extra])->toBe([Redactor::MASK, ['redaction_failed' => true]])
        ->and($outer->message)->toBe('outer two')
        ->and($outer->context['object'])->toBe(['ok' => true]);
});

test('the tap refuses a handler that cannot take processors', function () {
    $handler = new class implements HandlerInterface
    {
        public function isHandling(LogRecord $record): bool
        {
            return true;
        }

        public function handle(LogRecord $record): bool
        {
            return false;
        }

        public function handleBatch(array $records): void {}

        public function close(): void {}
    };

    expect(fn () => app(RedactLogs::class)(new Logger(new Monolog('probe', [$handler]))))->toThrow(LogicException::class);
});

test('a failed job\'s exception is stored masked, by shape and by the set it left its call with', function () {
    expect(app('queue.failer'))->toBeInstanceOf(RedactingFailedJobProvider::class);

    try {
        RedactionScope::during(RedactionSet::of(['FailedJobValue42']), fn () => throw new RuntimeException('Rejected FailedJobValue42 with token=FAILEDJOB2'));
    } catch (RuntimeException $exception) {
        app('queue.failer')->log('database', 'default', '{"uuid":"5d0f9f8e-3c1a-4c55-9a43-6a0e6f1b2c3d","job":"probe"}', $exception);
    }

    $stored = (string) DB::table('failed_jobs')->value('exception');
    expect($stored)->toContain('Rejected [redacted] with token=[redacted]')
        ->not->toContain('FailedJobValue42')->not->toContain('FAILEDJOB2')
        ->and(app('queue.failer')->count())->toBe(1);
});

test('the processor path keeps the 1 MB budget', function () {
    $handler = logRedactionChannel();
    $message = RedactorTiming::input(1 << 20, fn (int $index): string => "pw=a;token: v{$index} ");
    $set = RedactionSet::of(['LogBudgetValue1', 'LogBudgetValue2']);

    $started = hrtime(true);
    RedactionScope::during($set, fn () => Log::channel('redaction_probe')->info($message));

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0)
        ->and($handler->getRecords()[0]->message)->not->toContain('v7 ');
});

/*
 * #462 security review probes (emergency logger, nested Throwables, MessageLogged, the 128 KB line) and the log
 * manager that taps every channel.
 */

/** Writes $write while the emergency logger points at a fresh file, and returns what it wrote. */
function logRedactionEmergencyOutput(Closure $write): string
{
    $path = storage_path('logs/redaction-emergency-'.uniqid().'.log');
    config(['logging.channels.emergency.path' => $path]);

    try {
        $write();

        return (string) @file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

test('the emergency logger masks a channel that is not configured', function () {
    $written = logRedactionEmergencyOutput(fn () => Log::channel('redaction_not_configured')->error('Sync failed password=EMERGENCY1'));

    expect($written)->toContain('Sync failed password=[redacted]')->not->toContain('EMERGENCY1');
});

test('a channel whose tap throws falls back to the emergency logger masked', function () {
    config(['logging.channels.redaction_broken' => [
        'driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [RedactLogs::class, 'NoSuchTapClass'],
    ]]);

    $written = logRedactionEmergencyOutput(fn () => Log::channel('redaction_broken')->error('Sync failed token=EMERGENCY2'));

    expect($written)->toContain('Sync failed token=[redacted]')->not->toContain('EMERGENCY2');
});

test('a channel added to the config without the tap, Log::build and Log::stack are masked', function () {
    config(['logging.channels.redaction_untapped' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $untapped = Log::channel('redaction_untapped')->getLogger()->getHandlers()[0];
    $built = Log::build(['driver' => 'monolog', 'handler' => TestHandler::class]);
    $tapped = logRedactionChannel();

    Log::channel('redaction_untapped')->info('a password=UNTAPPED1');
    $built->info('b password=BUILT1');
    Log::stack(['redaction_probe'])->info('c password=STACK1');

    expect($untapped->getRecords()[0]->message)->toBe('a password=[redacted]')
        ->and($built->getLogger()->getHandlers()[0]->getRecords()[0]->message)->toBe('b password=[redacted]')
        ->and(array_map(fn (LogRecord $record): string => $record->message, $tapped->getRecords()))->toBe(['c password=[redacted]']);
});

test('a Throwable nested in context is masked by shape and its attached set, and logged as class, message and place', function () {
    $handler = logRedactionChannel();

    try {
        RedactionScope::during(RedactionSet::of(['NestedScope0042']), fn () => throw new RuntimeException('sent NestedScope0042 token=NESTED1'));
    } catch (RuntimeException $exception) {
        Log::channel('redaction_probe')->error('failed', ['wrapped' => ['e' => $exception]]);
    }

    $nested = $handler->getRecords()[0]->context['wrapped']['e'];
    expect($nested)->toBe([
        'exception' => RuntimeException::class,
        'message' => 'sent [redacted] token=[redacted]',
        'at' => $exception->getFile().':'.$exception->getLine(),
    ])->and($exception->getMessage())->toBe('sent [redacted] token=[redacted]');
});

test('MessageLogged listeners see the masked message and context', function () {
    $seen = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$seen) {
        $seen[] = $event->message.json_encode($event->context);
    });
    Log::listen(function (MessageLogged $event) use (&$seen) {
        $seen[] = $event->message;
    });
    logRedactionChannel();

    Log::channel('redaction_probe')->error('provider said password=LISTENER1', ['token' => 'LISTENER2']);

    expect($seen)->toHaveCount(2)
        ->and(implode('|', $seen))->toContain('provider said password=[redacted]')->not->toContain('LISTENER1')->not->toContain('LISTENER2');
});

test('a 128 KB line of masks joined by soft ends is logged in well under a second', function () {
    $handler = logRedactionChannel();
    $line = str_repeat('token=[redacted],', intdiv(128 * 1024, 17));

    $started = hrtime(true);
    Log::channel('redaction_probe')->info($line);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($handler->getRecords()[0]->message)->toBe('token=[redacted],');
});

test('the failed-job decorator is countable and prunable only when the wrapped provider is', function () {
    $nullProvider = RedactingFailedJobProvider::wrap(new NullFailedJobProvider, app(Redactor::class));

    expect(app('queue.failer'))->toBeInstanceOf(CountableFailedJobProvider::class)->toBeInstanceOf(PrunableFailedJobProvider::class)
        ->and($nullProvider)->toBeInstanceOf(CountableFailedJobProvider::class)->not->toBeInstanceOf(PrunableFailedJobProvider::class);
});

test('the scope is reset between a worker\'s jobs', function () {
    RedactionScope::during(RedactionSet::of(['LeftBehind0042']), function () {
        event(new Looping('database', 'default'));

        expect(RedactionScope::current()->isEmpty())->toBeTrue();
    });

    expect(RedactionScope::current()->isEmpty())->toBeTrue();
});
