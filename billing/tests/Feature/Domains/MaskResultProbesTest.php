<?php

/*
 * Review probes for #368 (rb368), adopted: an answer as a key or inside an object of meta, a string registrar id that contains an answer, and invalid UTF-8.
 */

use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    Currency::factory()->create();
    $this->registrar = FakeRegistrar::install();
    $this->registrar->tldFields['.us'] = [
        new SettingField('purpose', 'Purpose', FieldType::Select, required: true, options: ['P1' => 'Business']),
        new SettingField('id', 'Identification number', required: false),
        new SettingField('warranty', 'Eligible', FieldType::Boolean, required: true),
    ];
    Tld::factory()->extension('.us')->priced()->create(['registrar_key' => 'fake']);
    $this->client = Client::factory()->create();
    $this->submit = function (string $answer, RegistrarResult $result): array {
        $id = app(DomainRegistry::class)->createPending(new DomainDraft($this->client->id, 'asha.us', 'register', 1, 'USD', 1400, 1200, null, null, [], false, ['purpose' => 'P1', 'id' => $answer, 'warranty' => '1']));
        $this->registrar->will('register', $result);
        app(SubmitToRegistrar::class)->handle(Domain::query()->findOrFail($id));

        return [
            'log' => (string) json_encode(RegistrarCommandLog::query()->where('command', 'register')->value('response')),
            'meta' => Domain::query()->findOrFail($id)->registrar_meta,
        ];
    };
});

test('rb368 K: an answer echoed as a key of meta', function () {
    ['log' => $log] = ($this->submit)('51824753556', new RegistrarResult('completed', meta: ['checks' => ['518 247 535 56' => 'nexus ok']]));
    expect($log)->not->toContain('518 247 535 56');
});

test('rb368 O: an answer inside an object leaf of meta', function () {
    ['log' => $log] = ($this->submit)('51824753556', new RegistrarResult('completed', meta: ['raw' => (object) ['note' => 'nexus 518 247 535 56']]));
    expect($log)->not->toContain('518 247 535 56');
});

test('rb368 R: a string remote id that happens to contain a 4+ character answer', function () {
    ['meta' => $meta] = ($this->submit)('2026', new RegistrarResult('completed', remote: ['order_id' => 'ORD-2026-0042']));
    expect($meta['remote']['order_id'] ?? null)->toBe('ORD-2026-0042');
});

test('rb368 U: invalid UTF-8 in a meta string keeps its words and masks the answer', function () {
    ['log' => $log] = ($this->submit)('51824753556', new RegistrarResult('completed', meta: ['note' => "bad \xB1 byte, nexus 518 247 535 56"]));
    expect($log)->toContain('bad')->not->toContain('518 247 535 56');
});

test('rr368 N: a digits-only answer as a key of meta, which PHP stores as an integer key', function () {
    ['log' => $log] = ($this->submit)('51824753556', new RegistrarResult('completed', meta: ['checks' => ['51824753556' => 'nexus ok']]));
    expect($log)->not->toContain('51824753556');
});

test('rr368 N2: a regrouped answer as a key is masked without clobbering an existing [redacted] key', function () {
    ['log' => $log] = ($this->submit)('51824753556', new RegistrarResult('completed', meta: ['checks' => ['51-824-753-556' => 'ok', '[redacted]' => 'kept']]));
    expect($log)->not->toContain('51-824-753-556')->toContain('kept');
});
