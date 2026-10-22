<?php

/*
 * #190 re-check: the gateway's masked re-throw keeps the exception's class, retry flag and previous chain; only the
 * messages are masked, along the whole chain.
 */

use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Services\RegistrarGateway;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    FakeRegistrar::install();
});

function failingCall(string $capability, Throwable $exception): Throwable
{
    try {
        app(RegistrarGateway::class)->call('fake', $capability, fn () => throw $exception);
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new LogicException('The call did not fail.');
}

test('a driver exception subclass is re-thrown as itself with its message and previous masked', function () {
    $cause = new RuntimeException('GET https://api.test/x?api-key=K1 failed');
    $driverError = new class('Rejected: <item key="reg_password">P1</item>', true, $cause) extends RegistrarException {};

    $thrown = failingCall('info', $driverError);

    expect($thrown)->toBe($driverError)
        ->and($thrown->retryable)->toBeTrue()
        ->and($thrown->getMessage())->toBe('Rejected: <item key="reg_password">[redacted]</item>')
        ->and($thrown->getPrevious())->toBe($cause)
        ->and($cause->getMessage())->toBe('GET https://api.test/x?api-key=[redacted] failed')
        ->and(RegistrarCommandLog::query()->value('error'))->not->toContain('P1');
});

test('an unexpected error in a mutating call becomes a timeout that keeps the masked original as previous', function () {
    $original = new ErrorException('read timeout after sending passwd=ab;cd');

    $thrown = failingCall('register', $original);

    expect($thrown)->toBeInstanceOf(RegistrarTimeout::class)
        ->and($thrown->getMessage())->not->toContain('ab;cd')
        ->and($thrown->getPrevious())->toBe($original)
        ->and($original->getMessage())->toBe('read timeout after sending passwd=[redacted]');
});

test('an unexpected error in a read call stays retryable and keeps its previous', function () {
    $original = new TypeError('bad answer {"token":"T1"}');

    $thrown = failingCall('info', $original);

    expect($thrown)->toBeInstanceOf(RegistrarException::class)->not->toBeInstanceOf(RegistrarTimeout::class)
        ->and($thrown->retryable)->toBeTrue()
        ->and($thrown->getPrevious())->toBe($original)
        ->and($original->getMessage())->toBe('bad answer {"token":"[redacted]"}');
});
