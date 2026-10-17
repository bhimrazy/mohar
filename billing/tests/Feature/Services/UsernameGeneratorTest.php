<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\UsernameGenerator;

test('usernames come from the domain and get a numeric suffix within 8 characters on collision', function () {
    $server = Server::factory()->create();
    Service::factory()->create(['server_id' => $server->id, 'username' => 'examples']);

    expect(app(UsernameGenerator::class)->generate('example-shop.com', $server->id))->toBe('example1');
});

test('suffixes keep counting and uniqueness is per server', function () {
    $server = Server::factory()->create();
    Service::factory()->create(['server_id' => $server->id, 'username' => 'shop']);
    Service::factory()->create(['server_id' => $server->id, 'username' => 'shop1']);

    expect(app(UsernameGenerator::class)->generate('shop.io', $server->id))->toBe('shop2')
        ->and(app(UsernameGenerator::class)->generate('shop.io', Server::factory()->create()->id))->toBe('shop');
});

test('usernames start with a letter and fall back to random letters', function () {
    $generator = app(UsernameGenerator::class);

    expect($generator->generate('123abc.com', null))->toBe('abc')
        ->and($generator->generate('42.com', null))->toMatch('/^[a-z][a-z0-9]{7}$/');
});

test('random usernames can be enabled', function () {
    app(Settings::class)->set('services.random_usernames', true);

    expect(app(UsernameGenerator::class)->generate('example.com', null))->toMatch('/^[a-z][a-z0-9]{7}$/')->not->toBe('example');
});

test('generated passwords are 16 characters with mixed classes', function () {
    $password = app(UsernameGenerator::class)->password();

    expect(strlen($password))->toBe(16)->and($password)->toMatch('/[a-z]/')->toMatch('/[A-Z]/')->toMatch('/[0-9]/');
});
