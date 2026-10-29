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

test('the snapshot lists public signatures, modifiers, defaults, docblock types and markers, and leaves out untagged, internal and private members', function () {
    $lines = array_slice(explode("\n", $this->fixture->render()), 1, -1);
    $box = 'Tests\Fixtures\KernelApi\Support\Box';

    expect($lines)->toBe([
        'api '.Kernel::API_VERSION,
        '',
        'Tests\Fixtures\KernelApi\Contracts\Widget interface',
        "Tests\Fixtures\KernelApi\Contracts\Widget const public KIND = 'widget'",
        'Tests\Fixtures\KernelApi\Contracts\Widget method render(Tests\Fixtures\KernelApi\Enums\Size $size = Tests\Fixtures\KernelApi\Enums\Size::Small, ?string $title = null, string ...$classes): string',
        'Tests\Fixtures\KernelApi\Enums\Size enum: string @open',
        "Tests\Fixtures\KernelApi\Enums\Size case Large = 'lg'",
        "Tests\Fixtures\KernelApi\Enums\Size case Small = 'sm'",
        'Tests\Fixtures\KernelApi\Enums\Size method public label(): string',
        "{$box} class implements Tests\Fixtures\KernelApi\Contracts\Widget uses Tests\Fixtures\KernelApi\Support\Stamps",
        "{$box} doc class @template TItem of Widget",
        "{$box} const protected LIMIT = 3",
        "{$box} const public final string NAME = 'box'",
        "{$box} property public array \$labels = [\"new\"]",
        "{$box} doc property \$labels @var list<string>",
        "{$box} property protected array \$tags",
        "{$box} property public readonly int \$width",
        "{$box} property public DateTimeZone \$zone",
        "{$box} method public __construct(int \$width, array \$tags = [\"a\"], DateTimeZone \$zone = new DateTimeZone('UTC'))",
        "{$box} doc method __construct @param \$tags array<int, string>",
        "{$box} method public static draw(int &\$count): void @deprecated",
        "{$box} method protected filter(callable \$filter): array",
        "{$box} doc method filter @param \$filter callable(TItem): bool",
        "{$box} doc method filter @return list<TItem>",
        "{$box} method public render(Tests\Fixtures\KernelApi\Enums\Size \$size = Tests\Fixtures\KernelApi\Enums\Size::Small, ?string \$title = null, string ...\$classes): string",
        "{$box} doc method render @return non-empty-string",
    ]);
});

test('every App type a kernel signature names is itself in the snapshot or is a model, and no module class is', function () {
    $lines = array_filter(explode("\n", (string) file_get_contents(base_path(KernelApiSnapshot::PATH))), fn (string $line): bool => str_starts_with($line, 'App\\'));
    $declared = array_flip(array_map(fn (string $line): string => strtok($line, ' '), $lines));
    $missing = [];

    // Doc lines keep names as imported, so only native signatures are checked.
    foreach ($lines as $line) {
        if (str_contains($line, ' doc ')) {
            continue;
        }

        preg_match_all('/(?<![\w\\\\])App\\\\[\w\\\\]*\w/', $line, $names);

        foreach ($names[0] as $type) {
            if (! isset($declared[$type]) && ! str_starts_with($type, 'App\Models\\')) {
                $missing[$type] = strtok($line, ' ');
            }
        }
    }

    expect($missing)->toBe([]);
});

test('--check fails on an unrecorded change, names the kernel-changelog fragment and leaves the file alone', function () {
    app()->instance(KernelApiSnapshot::class, $this->fixture);
    $this->artisan('kernel:api-snapshot')->assertSuccessful();
    $stale = str_replace('array $tags = ["a"],', 'array $tags,', (string) file_get_contents($this->fixture->path));
    file_put_contents($this->fixture->path, $stale);

    $this->artisan('kernel:api-snapshot', ['--check' => true])
        ->expectsOutputToContain('does not record it (ADR 0010)')
        ->expectsOutputToContain('docs/architecture/kernel-changelog.d/<branch-slug>.md')
        ->expectsOutputToContain('- Tests\Fixtures\KernelApi\Support\Box method public __construct(int $width, array $tags, DateTimeZone')
        ->expectsOutputToContain('+ Tests\Fixtures\KernelApi\Support\Box method public __construct(int $width, array $tags = ["a"], DateTimeZone')
        ->assertFailed();

    expect(file_get_contents($this->fixture->path))->toBe($stale);
});
