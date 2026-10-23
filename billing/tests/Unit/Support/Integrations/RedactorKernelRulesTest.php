<?php

/*
 * ADR 0023 step 3a: the develop bugs of #383 and #309, keys and numbers holding a value, the unwrap cap and
 * maskThrowable(). Each bug case was reproduced on develop f5290aa5 before the fix.
 */

use App\Data\Billing\GatewayConfig;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;

test('#383: a secret echoed in another case or HTML-escaped is masked', function () {
    $redactor = new Redactor;

    expect($redactor->maskValues('Login Hunter22x refused', ['hunter22x']))->toBe('Login [redacted] refused')
        ->and($redactor->maskValues('<p>key '.htmlspecialchars('p&ss<w0rd>').'</p>', ['p&ss<w0rd>']))->toBe('<p>key [redacted]</p>')
        // An entity spelling the forms do not list is found by decoding the word, and the whole word is masked.
        ->and($redactor->maskValues('value=it&#39;s-a-key end', ["it's-a-key"]))->toBe('[redacted] end');
});

test('#383: a 3-character secret no longer masks every equal run', function () {
    expect((new Redactor)->maskValues('abc failed: abcdef is not abc', ['abc']))->toBe('abc failed: abcdef is not abc');
});

test('#383: a JsonSerializable that returns itself ends masked at the depth cap', function () {
    $loop = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return $this;
        }
    };

    expect((new Redactor)->redact(['answer' => $loop, 'ok' => 1]))->toBe(['answer' => '[redacted]', 'ok' => 1])
        ->and((new Redactor)->redactPayment(['answer' => $loop]))->toBe(['answer' => '[redacted]']);
});

test('#309: a non-ASCII element name is a tag, so an HTML marker in its attribute is not seen', function () {
    $xml = '<é t="<html>"><param name="password"><value>s3cretValue9</value>';

    expect((new Redactor)->maskValues($xml, []))->not->toContain('s3cretValue9');
});

test('a number that holds a value is withheld, as a leaf and inside a decoded JSON string', function () {
    $set = RedactionSet::of([], ['51824753556']);

    expect((new Redactor)->redact(['citizenship' => 51824753556, 'id' => 7, 'rate' => 1.5, 'ok' => true], $set))
        ->toBe(['citizenship' => '[redacted]', 'id' => 7, 'rate' => 1.5, 'ok' => true])
        ->and((new Redactor)->redact(['raw' => '{"citizenship":51824753556,"id":7}'], $set))->toBe(['raw' => '{"citizenship":"[redacted]","id":7}']);
});

test('a key shaped like an exponent number still reaches the answer patterns', function () {
    expect(RedactionSet::of([], ['E1234567'])->matches('E1234567'))->toBeTrue();
});

test('matches() remembers only short texts', function () {
    $set = RedactionSet::of(['hunter22x']);
    $set->matches(str_repeat('a', 300));
    $set->matches('short');

    expect(array_keys((fn () => $this->matchCache)->call($set)))->toBe(['short']);
});

test('a key that holds a value is renamed and its value withheld, numbered when it repeats', function () {
    $redacted = (new Redactor)->redact([
        'hunter22x' => 'a',
        'HUNTER22X-copy' => ['nested' => 'b'],
        'raw' => '{"hunter22x":"x","fine":"y"}',
        98123 => 'number key without the value',
    ], ['hunter22x']);

    expect($redacted)->toBe([
        '[redacted]' => '[redacted]',
        '[redacted] 2' => '[redacted]',
        'raw' => '{"[redacted]":"[redacted]","fine":"y"}',
        98123 => 'number key without the value',
    ]);
});

test('maskThrowable masks the chain in place and keeps class, code, previous and the rest', function () {
    $root = new InvalidArgumentException('root saw Hunter22x', 7);
    $middle = new LogicException('middle with 981 234 5678', 0, $root);
    $error = new class('top: token=abc123def and hunter22x', 3, $middle) extends RuntimeException
    {
        public bool $retryable = true;
    };

    $masked = (new Redactor)->maskThrowable($error, RedactionSet::of(['hunter22x'], ['9812345678']));

    expect($masked)->toBe($error)
        ->and($error->getMessage())->toBe('top: token=[redacted] and [redacted]')
        ->and($error->getCode())->toBe(3)
        ->and($error->retryable)->toBeTrue()
        ->and($error->getPrevious())->toBe($middle)
        ->and($middle->getMessage())->toBe('middle with [redacted]')
        ->and($root->getMessage())->toBe('root saw [redacted]')
        ->and($root->getCode())->toBe(7);
});

test('maskThrowable masks an Error, and withholds every message past 16 exceptions behind the first', function () {
    $chain = new Exception('hunter22x at 17');

    for ($i = 16; $i >= 0; $i--) {
        $chain = new Exception("hunter22x at {$i}", 0, $chain);
    }

    $error = new TypeError('hunter22x in a type error');
    (new Redactor)->maskThrowable($error, ['hunter22x']);
    (new Redactor)->maskThrowable($chain, ['hunter22x']);
    $messages = [];

    for ($link = $chain; $link !== null; $link = $link->getPrevious()) {
        $messages[] = $link->getMessage();
    }

    expect($error->getMessage())->toBe('[redacted] in a type error')
        ->and($messages[16])->toBe('[redacted] at 16')
        ->and($messages[17])->toBe('[redacted]');
});

test('the mail normaliser decodes nested entities and percent-encoding', function () {
    expect(SecretTemplatePolicy::normalise('Key: &amp;#x48;unter%2532'))->toBe('key: hunter2');
});

test('JSON \\u escapes of ASCII characters are masked inside a line, as Go and JSON_HEX_* write them', function () {
    $secret = 'p&ss<w0rd>9';
    $go = strtr($secret, ['&' => '\\u0026', '<' => '\\u003c', '>' => '\\u003e']);
    $redactor = new Redactor;

    expect($redactor->maskValues('HTTP 400: {"error":"invalid key '.$go.'"} retrying', [$secret]))->not->toContain('ss')
        ->and($redactor->maskValues('body '.substr((string) json_encode($secret, JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1), [$secret]))->toBe('body [redacted]')
        ->and($redactor->maskValues('err \\u0068unter22x', ['hunter22x']))->toBe('err [redacted]');
});

test('a gateway config lists only its secret-typed settings', function () {
    $config = new GatewayConfig('esewa', 'eSewa', ['merchant_code' => 'EPAYTEST', 'secret_key' => '8gBm/:&EnhH.1/q', 'empty' => ''], secretSettingKeys: ['secret_key', 'empty']);

    expect($config->secrets())->toBe(['8gBm/:&EnhH.1/q']);
});

test('#399: a header value the set already masked is masked once, with no stray bracket', function (string $header, array $secrets) {
    expect((new Redactor)->maskValues($header."\r\nHost: a.test", $secrets))->toBe(explode(':', $header)[0].": [redacted]\r\nHost: a.test")
        ->and((new Redactor)->maskValues('sent '.$header.', retrying', $secrets))->toBe('sent '.explode(':', $header)[0].': [redacted], retrying');
})->with([
    'basic, password in the set' => ['Authorization: Basic '.base64_encode('reseller:hunter22x'), ['hunter22x']],
    'basic, the whole pair in the set' => ['Authorization: Basic '.base64_encode('reseller:hunter22x'), ['reseller:hunter22x']],
    'bearer token in the set' => ['Authorization: Bearer tok3nValue99', ['tok3nValue99']],
    'api key header' => ['X-Api-Key: k3yValue77', ['k3yValue77']],
]);

test('#399: a pair value the set masked in part is masked once, and a bracket of the text after it is kept', function () {
    $basic = base64_encode('reseller:hunter22x');

    expect((new Redactor)->maskValues("auth={$basic}&id=5", ['hunter22x']))->toBe('auth=[redacted]&id=5')
        ->and((new Redactor)->maskValues("[token={$basic}] y", ['hunter22x']))->toBe('[token=[redacted]] y');
});

/** A JsonSerializable that fans out into two new copies of itself, counting how many were read. */
function redactorFanOut(int &$reads, int $stopAfter): JsonSerializable
{
    return new class($reads, $stopAfter) implements JsonSerializable
    {
        public function __construct(private int &$reads, private int $stopAfter) {}

        public function jsonSerialize(): mixed
        {
            return ++$this->reads >= $this->stopAfter ? 'leaf' : [new self($this->reads, $this->stopAfter), new self($this->reads, $this->stopAfter)];
        }
    };
}

test('#399: an object that fans out is read only up to the node budget, and the rest of it is masked', function (string $method) {
    $reads = 0;
    // The object stops fanning out on its own after 200,000 reads, so a missing budget fails here instead of exhausting memory.
    $redacted = (new Redactor)->{$method}(['answer' => redactorFanOut($reads, 200_000), 'id' => 7]);

    expect($reads)->toBeLessThan(100_000)
        ->and($redacted['id'])->toBe(7)
        ->and(json_encode($redacted))->toContain('[redacted]')->not->toContain('leaf');
})->with(['redact', 'redactPayment']);

test('#399: the node budget starts over on every call', function () {
    $redactor = new Redactor;
    $reads = 0;
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $redactor->redact(['answer' => redactorFanOut($reads, PHP_INT_MAX)]);

    expect(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024)
        ->and($redactor->redact(['id' => 7, 'note' => 'ok']))->toBe(['id' => 7, 'note' => 'ok'])
        ->and($redactor->redactPayment(['id' => 'pi_1']))->toBe(['id' => 'pi_1']);
});
