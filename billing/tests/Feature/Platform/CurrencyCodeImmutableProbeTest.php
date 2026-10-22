<?php

use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    actingAsStaff(['core.currencies.manage', 'core.currencies.rates']);
    Currency::factory()->create();
    $this->npr = Currency::factory()->npr()->create();
});

test('probe: an array code on update is a validation error, not a 500', function () {
    $this->put(route('admin.platform.currencies.update', $this->npr), ['code' => ['NRS'], 'name' => 'X', 'decimals' => 2, 'format' => '1,23,456.78'])
        ->assertStatus(302);
    expect($this->npr->fresh()->code)->toBe('NPR');
});

test('probe: staff with manage only cannot rename via update', function () {
    actingAsStaff(['core.currencies.manage']);
    $this->put(route('admin.platform.currencies.update', $this->npr), ['code' => 'NRS', 'name' => 'X', 'decimals' => 2, 'format' => '1,23,456.78'])
        ->assertSessionHasErrors('code');
    expect($this->npr->fresh()->code)->toBe('NPR');
});

test('probe: make default and rate update still work with the guard', function () {
    confirmPassword();
    $this->post(route('admin.platform.currencies.default', $this->npr))->assertSessionHasNoErrors();
    expect($this->npr->fresh()->is_default)->toBeTrue();
});

test('probe: query-builder update bypasses the model guard (documented gap)', function () {
    Currency::query()->whereKey($this->npr->id)->update(['code' => 'NRS']);
    expect(DB::table('currencies')->where('id', $this->npr->id)->value('code'))->toBe('NRS');
});

test('probe: same-value assignment with different case is refused by the model', function () {
    $this->npr->code = 'npr';
    expect(fn () => $this->npr->save())->toThrow(LogicException::class);
});
