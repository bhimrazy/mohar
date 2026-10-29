<?php

use App\Modules\Core\Support\KernelApiSnapshot;
use App\Support\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/kernel-api-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    $this->fixture = new KernelApiSnapshot(base_path('tests/Fixtures/KernelApi'), 'Tests\\Fixtures\\KernelApi\\', $this->dir.'/kernel-api.txt');
});

afterEach(fn () => File::deleteDirectory($this->dir));

// This is the CI check (ADR 0010): on failure the message says how to regenerate the file and which fragment to add.
test('the committed snapshot records the current kernel surface', function () {
    $exitCode = Artisan::call('kernel:api-snapshot', ['--check' => true]);

    expect($exitCode)->toBe(0, Artisan::output());

    expect(Kernel::API_VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});

test('the snapshot lists public signatures, defaults and markers, and leaves out untagged and private members', function () {
    $lines = array_slice(explode("\n", $this->fixture->render()), 1, -1);

    expect($lines)->toBe([
        'api '.Kernel::API_VERSION,
        '',
        'Tests\Fixtures\KernelApi\Contracts\Widget interface',
        "Tests\Fixtures\KernelApi\Contracts\Widget const KIND = 'widget'",
        'Tests\Fixtures\KernelApi\Contracts\Widget method render(Tests\Fixtures\KernelApi\Enums\Size $size = Tests\Fixtures\KernelApi\Enums\Size::Small, ?string $title = null, string ...$classes): string',
        'Tests\Fixtures\KernelApi\Enums\Size enum: string @open',
        "Tests\Fixtures\KernelApi\Enums\Size case Large = 'lg'",
        "Tests\Fixtures\KernelApi\Enums\Size case Small = 'sm'",
        'Tests\Fixtures\KernelApi\Enums\Size method public label(): string',
        'Tests\Fixtures\KernelApi\Support\Box final class implements Tests\Fixtures\KernelApi\Contracts\Widget uses Tests\Fixtures\KernelApi\Support\Stamps',
        'Tests\Fixtures\KernelApi\Support\Box property public readonly int $width',
        'Tests\Fixtures\KernelApi\Support\Box method public __construct(int $width, array $tags = ["a"])',
        'Tests\Fixtures\KernelApi\Support\Box method public static draw(int &$count): void @deprecated',
        'Tests\Fixtures\KernelApi\Support\Box method public render(Tests\Fixtures\KernelApi\Enums\Size $size = Tests\Fixtures\KernelApi\Enums\Size::Small, ?string $title = null, string ...$classes): string',
    ]);
});

test('--check fails on an unrecorded change, names the kernel-changelog fragment and leaves the file alone', function () {
    app()->instance(KernelApiSnapshot::class, $this->fixture);
    $this->artisan('kernel:api-snapshot')->assertSuccessful();
    $stale = str_replace(' = ["a"]', '', (string) file_get_contents($this->fixture->path));
    file_put_contents($this->fixture->path, $stale);

    $this->artisan('kernel:api-snapshot', ['--check' => true])
        ->expectsOutputToContain('does not record it (ADR 0010)')
        ->expectsOutputToContain('docs/architecture/kernel-changelog.d/<branch-slug>.md')
        ->expectsOutputToContain('- Tests\Fixtures\KernelApi\Support\Box method public __construct(int $width, array $tags)')
        ->expectsOutputToContain('+ Tests\Fixtures\KernelApi\Support\Box method public __construct(int $width, array $tags = ["a"])')
        ->assertFailed();

    expect(file_get_contents($this->fixture->path))->toBe($stale);
});
