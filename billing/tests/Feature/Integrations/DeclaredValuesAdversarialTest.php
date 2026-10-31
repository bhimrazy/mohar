<?php

/*
 * Re-review probes for #574 round 4 (head 35435007). Blocking: repeatsKey()'s key pattern exhausts the PCRE JIT stack
 * on a JSON string of about 9 KB followed by another key, so valuesUnder() withholds the whole body (the three
 * "long string" tests fail; all pass at 70e6b77b). The other tests pass and pin the chain, spelling and decode limits.
 * Adopt the failing ones into tests/Feature/Integrations/DeclaredKeysMaskingTest.php.
 */

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Integrations\DeclaresSensitiveKeys;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Service;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;
use Tests\Fakes\Services\ServicesTestKit;

const ADV_CODE = 'ZXCV-99812-QWER';

/** @return list<string> the thrown chain's messages, top first, with a cycle guard */
function advRegistrarThrow(Throwable $exception): array
{
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }
    }, replace: true);

    try {
        app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn (): never => throw $exception);
    } catch (Throwable $thrown) {
        $messages = [];
        $seen = new SplObjectStorage;

        for ($link = $thrown; $link !== null && ! $seen->contains($link); $link = $link->getPrevious()) {
            $seen->attach($link);
            $messages[] = $link->getMessage();
        }

        return $messages;
    }

    return [];
}

function advChain(int $links, string $deepest): RuntimeException
{
    $exception = new RuntimeException($deepest);

    for ($link = 1; $link < $links; $link++) {
        $exception = new RuntimeException("wrapped {$link}", previous: $exception);
    }

    return $exception;
}

test('a registrar exception chain with a cycle terminates and leaks nothing', function () {
    $inner = new RuntimeException('<ticket>'.ADV_CODE.'</ticket>');
    $outer = new RuntimeException('call failed', previous: $inner);
    (new ReflectionProperty(Exception::class, 'previous'))->setValue($inner, $outer);

    $messages = advRegistrarThrow($outer);

    expect(json_encode($messages))->not->toContain(ADV_CODE)
        ->and(json_encode(RegistrarCommandLog::all()))->not->toContain(ADV_CODE);
});

test('a registrar exception chain of exactly 16 links is read and masked, not withheld', function () {
    $messages = advRegistrarThrow(advChain(16, '<ticket>'.ADV_CODE.'</ticket> refused '.ADV_CODE));

    expect($messages)->toHaveCount(17)
        ->and($messages[16])->toBe('<ticket>[redacted]</ticket> refused [redacted]')
        ->and($messages[1])->toBe('wrapped 15');
});

test('a registrar exception chain of 17 links is withheld', function () {
    $messages = advRegistrarThrow(advChain(17, '<ticket>'.ADV_CODE.'</ticket>'));

    expect($messages)->toHaveCount(18)
        ->and(array_values(array_unique(array_slice($messages, 1))))->toBe(['[redacted]']);
});

test('valuesUnder reads JSON key spellings', function (string $body, ?array $expected) {
    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe($expected);
})->with([
    'whitespace before the colon' => ['{"ticket" : "'.ADV_CODE.'"}', [ADV_CODE]],
    'newline and tab before the colon' => ["{\"ticket\"\n\t:\"".ADV_CODE.'"}', [ADV_CODE]],
    'unicode-escaped key once' => ['{"\\u0074icket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'repeated, whitespace before the second colon' => ['{"ticket":"'.ADV_CODE.'","ticket"  :"BBBB-2222-yyyy"}', null],
    'repeated, all-escaped second key' => ['{"ticket":"'.ADV_CODE.'","\\u0074\\u0069\\u0063\\u006b\\u0065\\u0074":"BBBB-2222-yyyy"}', null],
    'repeated, escaped solidus-free surrogate form' => ['{"ticket":"'.ADV_CODE.'","t\\u0069cket":"BBBB-2222-yyyy"}', null],
    'repeated inside an array element' => ['[{"ticket":"'.ADV_CODE.'","ticket":"BBBB-2222-yyyy"}]', null],
    'repeated in JSON inside a JSON string' => ['{"body":"{\\"ticket\\":\\"'.ADV_CODE.'\\",\\"ticket\\":\\"BBBB-2222-yyyy\\"}"}', null],
    'differently cased keys are both read' => ['{"Ticket":"'.ADV_CODE.'","ticket":"BBBB-2222-yyyy"}', [ADV_CODE, 'BBBB-2222-yyyy']],
]);

test('valuesUnder does not miscount key-like text inside JSON string values', function (string $body, ?array $expected) {
    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe($expected);
})->with([
    'the word as a value' => ['{"note":"ticket","ticket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'the word in an array' => ['{"a":["ticket","x"],"ticket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'a value ending in a backslash' => ['{"a":"x\\\\","ticket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'a value holding an escaped quote' => ['{"a":"he said \\"","ticket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'a key starting with a colon' => ['{"a":"x",":b":1,"ticket":"'.ADV_CODE.'"}', [ADV_CODE]],
    'many objects, one ticket each' => ['[{"ticket":"'.ADV_CODE.'"},{"ticket":"BBBB-2222-yyyy"}]', [ADV_CODE, 'BBBB-2222-yyyy']],
]);

test('valuesUnder decodes percent-encoding six times, then fails closed', function (int $times, ?array $expected) {
    $pair = 'ticket='.ADV_CODE;

    for ($i = 0; $i < $times; $i++) {
        $pair = rawurlencode($pair);
    }

    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => 'next='.$pair]))->toBe($expected);
})->with([
    'six times' => [6, [ADV_CODE]],
    'seven times' => [7, null],
]);

test('valuesUnder does not withhold ordinary bodies with percent signs', function (string $body) {
    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe([ADV_CODE]);
})->with([
    'a literal percent' => ['50% off, ticket='.ADV_CODE],
    'an encoded percent' => ['rate=100%25&ticket='.ADV_CODE],
]);

test('a JSON body holding a long string is read, not withheld', function (int $bytes) {
    $body = json_encode(['blob' => str_repeat('x', $bytes), 'ticket' => ADV_CODE]);

    // At 35435007 repeatsKey()'s pattern exhausts the PCRE JIT stack on a string of about 10 KB: preg_match_all() is
    // false, so every JSON body with a long string is withheld, whether or not it holds a declared key.
    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe([ADV_CODE]);
})->with(['9 KB' => [9_000], '10 KB' => [10_000], '64 KB' => [65_536], '1 MB' => [1 << 20]]);

test('a module command whose JSON answer holds a long string keeps its readable error', function () {
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::fail('Panel refused the suspension', response: ['body' => json_encode(['certificate' => str_repeat('A', 16_384), 'status' => 'error'])]);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();
    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    expect(ModuleCommand::query()->sole()->error)->toBe('Panel refused the suspension');
});

test('valuesUnder reads a 1 MB JSON body with many keys and long strings in linear time', function () {
    $body = fn (int $bytes): string => json_encode(['blob' => str_repeat('a\\"b', intdiv($bytes, 8)), 'items' => array_map(fn (int $i): array => ['ticket' => sprintf('T%07d-abcd', $i), 'note' => str_repeat('n', 40)], range(1, 1000))]);
    $time = function (string $text): array {
        $started = hrtime(true);
        $values = app(Redactor::class)->valuesUnder(['ticket'], ['body' => $text]);

        return [$values, (hrtime(true) - $started) / 1e9];
    };

    [$smallValues, $smallSeconds] = $time($body(128 * 1024));
    [$largeValues, $largeSeconds] = $time($body(1024 * 1024));

    expect($smallValues)->toHaveCount(1000)
        ->and($largeValues)->toHaveCount(1000)
        ->and($largeSeconds)->toBeLessThan(2.0)
        ->and($largeSeconds)->toBeLessThan(max(0.25, $smallSeconds * 16));
});

test('valuesUnder reads a 1 MB form body with many pairs in linear time', function () {
    $body = fn (int $bytes): string => implode('&', array_map(fn (int $i): string => 'a'.$i.'=%2Fx%253F&ticket='.sprintf('T%07d-abcd', $i), range(1, intdiv($bytes, 40))));
    $redactor = app(Redactor::class);

    $started = hrtime(true);
    $values = $redactor->valuesUnder(['ticket'], ['body' => $body(1024 * 1024)]);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($values)->not->toBeNull()->and($seconds)->toBeLessThan(2.0);
});
