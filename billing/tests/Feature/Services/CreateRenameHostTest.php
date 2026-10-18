<?php

use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Fakes\Services\ServicesTestKit;

/**
 * How the host handles a create that proposes another username (DriverResult::renameUsername()), with a driver that
 * records what it was given: proposes the queued names in turn, then creates.
 *
 * @param  list<string>  $proposals
 */
function renamingDriver(array $proposals): object
{
    return new class($proposals) implements ProvisioningDriver
    {
        /** @var list<ProvisioningContext> */
        public array $contexts = [];

        /** @param list<string> $proposals */
        public function __construct(public array $proposals) {}

        public function key(): string
        {
            return 'renamer';
        }

        public function name(): string
        {
            return 'Renamer';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true);
        }

        public function create(ProvisioningContext $context): DriverResult
        {
            $this->contexts[] = $context;
            $next = array_shift($this->proposals);

            return $next === null
                ? DriverResult::ok('Created.', request: ['target' => 'forged by the driver'])
                : DriverResult::renameUsername($next, 'Taken on the server.');
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::ok();
        }

        public function unsuspend(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }

        public function terminate(ProvisioningContext $context): DriverResult
        {
            return DriverResult::ok();
        }
    };
}

beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'renamer']);
    $this->kit->priceBook->addProduct(51, 'Renamed', driver: 'renamer');
});

function renamingService(object $driver, Server $server): Service
{
    app(ProvisioningDriverRegistry::class)->register('renamer', fn () => $driver, replace: true);

    return Service::factory()->pending()->create(['product_id' => 51, 'server_id' => $server->id, 'username' => 'examples', 'domain' => 'example-shop.com']);
}

test('renamed attempts share the one deadline of the job, and each records the name it sends', function () {
    $driver = renamingDriver(['example1', 'example2']);
    $service = renamingService($driver, $this->server);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($driver->contexts)->toHaveCount(3)
        ->and(array_unique(array_map(fn ($context) => $context->deadline?->toIso8601String(), $driver->contexts)))->toHaveCount(1)
        ->and(array_map(fn ($context) => $context->service->username, $driver->contexts))->toBe(['examples', 'example1', 'example2'])
        ->and($service->refresh()->username)->toBe('example2')
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and(ModuleCommand::query()->where('service_id', $service->id)->orderBy('id')->pluck('request')->map(fn ($request) => $request['target']['username'])->all())->toBe(['examples', 'example1', 'example2']);
});

test('a proposed name another service holds is not taken; the host chooses the next free one', function () {
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'example1']);
    $driver = renamingDriver(['example1']);
    $service = renamingService($driver, $this->server);

    app(ModuleCommandDispatcher::class)->runNow($service, ModuleCommandName::Create);

    expect($service->refresh()->username)->toBe('example2')
        ->and($driver->contexts[1]->service->username)->toBe('example2');
});

test('a username is unique per server', function () {
    Service::factory()->create(['server_id' => $this->server->id, 'username' => 'shop1']);

    expect(fn () => Service::factory()->create(['server_id' => $this->server->id, 'username' => 'shop1']))->toThrow(UniqueConstraintViolationException::class);
});
