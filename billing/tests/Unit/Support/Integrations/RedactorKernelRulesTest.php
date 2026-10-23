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

test('maskThrowable masks an Error and stops after 16 exceptions behind the first', function () {
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
        ->and($messages[17])->toBe('hunter22x at 17');
});

test('the mail normaliser is the kernel one', function () {
    $text = 'Key: &amp;#x48;unter%2532';

    expect(SecretTemplatePolicy::normalise($text))->toBe(RedactionSet::normalise($text))->toBe('key: hunter2');
});

test('a gateway config lists only its secret-typed settings', function () {
    $config = new GatewayConfig('esewa', 'eSewa', ['merchant_code' => 'EPAYTEST', 'secret_key' => '8gBm/:&EnhH.1/q', 'empty' => ''], secretSettingKeys: ['secret_key', 'empty']);

    expect($config->secrets())->toBe(['8gBm/:&EnhH.1/q']);
});
