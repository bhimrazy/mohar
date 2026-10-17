<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign in as a staff member whose role grants the given permissions ("*" = all).
 *
 * @param  list<string>  $permissions
 */
function actingAsStaff(array $permissions = ['*']): User
{
    $user = User::factory()->staff($permissions)->create();
    test()->actingAs($user);

    return $user;
}

/**
 * Sign in as the owner of a (new or given) client account. Returns [user, client].
 *
 * @return array{0: User, 1: Client}
 */
function actingAsClient(?Client $client = null): array
{
    Currency::factory()->create();
    $client ??= Client::factory()->create();
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);
    test()->actingAs($user);

    return [$user, $client];
}

/**
 * A real PNG upload without the GD extension (UploadedFile::fake()->image() needs GD).
 * $noise fills pixels with random bytes so the file is incompressible (size tests).
 */
function fakePng(string $name, int $width, int $height, bool $noise = false): UploadedFile
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $row = $noise ? null : "\0".str_repeat("\x4F\x4B\xC4", $width);
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= $row ?? "\0".random_bytes($width * 3);
    }

    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress($raw, $noise ? 0 : 9))
        .$chunk('IEND', '');

    return UploadedFile::fake()->createWithContent($name, $png);
}
