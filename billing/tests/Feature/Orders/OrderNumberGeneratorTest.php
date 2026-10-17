<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderNumberGenerator;

it('generates 10-digit numbers without a leading zero', function () {
    $number = (new OrderNumberGenerator)->generate();

    expect($number)->toMatch('/^[1-9]\d{9}$/');
});

it('retries when a generated number already exists', function () {
    Order::factory()->create(['number' => '1111111111']);
    $candidates = ['1111111111', '2222222222'];

    $number = (new OrderNumberGenerator(function () use (&$candidates): string {
        return array_shift($candidates);
    }))->generate();

    expect($number)->toBe('2222222222');
});

it('gives up after repeated collisions', function () {
    Order::factory()->create(['number' => '1111111111']);

    (new OrderNumberGenerator(fn (): string => '1111111111'))->generate();
})->throws(RuntimeException::class);
