<?php

namespace Mohar\Simulators\Kernel;

use InvalidArgumentException;
use Mohar\Simulators\Contracts\Simulator;
use RuntimeException;

/**
 * One simulator run: its directory under the system temp dir (state, certificates, request log, pids), its ports and
 * the control-plane token. Never the app's database or port 8000.
 */
final class Scenario
{
    /** Ports a scenario never takes: the owner's preview server. */
    public const FORBIDDEN_PORTS = [8000];

    private ?StateStore $state = null;

    private ?Simulator $simulator = null;

    /**
     * @param  array{id: string, simulator: class-string<Simulator>, token: string, api_port: int, backend_port: int, control_port: int, pids: list<int>}  $config
     */
    private function __construct(public readonly string $directory, private array $config) {}

    public static function root(): string
    {
        return sys_get_temp_dir().'/mohar-sim';
    }

    /**
     * @param  class-string<Simulator>  $simulator
     */
    public static function create(string $simulator): self
    {
        $id = (new $simulator)->key().'-'.bin2hex(random_bytes(4));
        $directory = self::root().'/'.$id;

        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        $scenario = new self($directory, [
            'id' => $id,
            'simulator' => $simulator,
            'token' => bin2hex(random_bytes(24)),
            'api_port' => self::freePort(),
            'backend_port' => self::freePort(),
            'control_port' => self::freePort(),
            'pids' => [],
        ]);
        $scenario->save();
        $scenario->ca()->create();
        $scenario->simulator()->seed($scenario);

        return $scenario;
    }

    public static function load(string $directory): self
    {
        $config = json_decode((string) @file_get_contents($directory.'/scenario.json'), true);
        $simulator = is_array($config) ? ($config['simulator'] ?? null) : null;

        if (! is_array($config) || ! is_string($simulator) || ! is_subclass_of($simulator, Simulator::class)) {
            throw new InvalidArgumentException("No scenario in {$directory}.");
        }

        return new self($directory, [
            'id' => (string) ($config['id'] ?? ''),
            'simulator' => $simulator,
            'token' => (string) ($config['token'] ?? ''),
            'api_port' => (int) ($config['api_port'] ?? 0),
            'backend_port' => (int) ($config['backend_port'] ?? 0),
            'control_port' => (int) ($config['control_port'] ?? 0),
            'pids' => array_values(array_map('intval', (array) ($config['pids'] ?? []))),
        ]);
    }

    public function id(): string
    {
        return $this->config['id'];
    }

    public function token(): string
    {
        return $this->config['token'];
    }

    public function apiPort(): int
    {
        return $this->config['api_port'];
    }

    public function backendPort(): int
    {
        return $this->config['backend_port'];
    }

    public function controlPort(): int
    {
        return $this->config['control_port'];
    }

    public function apiUrl(): string
    {
        return 'https://127.0.0.1:'.$this->apiPort();
    }

    public function controlUrl(): string
    {
        return 'http://127.0.0.1:'.$this->controlPort();
    }

    /**
     * @return list<int>
     */
    public function pids(): array
    {
        return $this->config['pids'];
    }

    /**
     * @param  list<int>  $pids
     */
    public function withPids(array $pids): void
    {
        $this->config['pids'] = $pids;
        $this->save();
    }

    public function simulator(): Simulator
    {
        return $this->simulator ??= new $this->config['simulator'];
    }

    public function state(): StateStore
    {
        return $this->state ??= new StateStore($this->directory.'/state.sqlite');
    }

    public function ca(): ScenarioCa
    {
        return new ScenarioCa($this->directory);
    }

    public function log(): RequestLog
    {
        return new RequestLog($this->state());
    }

    public function faults(): FaultPlan
    {
        return new FaultPlan($this->state());
    }

    public function outbox(): CallbackDispatcher
    {
        return new CallbackDispatcher($this->state(), $this);
    }

    /**
     * An ephemeral port on 127.0.0.1, never a forbidden one.
     */
    public static function freePort(): int
    {
        do {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

            if ($socket === false) {
                throw new RuntimeException("No free port: {$errorMessage}");
            }

            $name = (string) stream_socket_get_name($socket, false);
            fclose($socket);
            $port = (int) substr($name, strrpos($name, ':') + 1);
        } while (in_array($port, self::FORBIDDEN_PORTS, true));

        return $port;
    }

    private function save(): void
    {
        file_put_contents($this->directory.'/scenario.json', json_encode($this->config, JSON_PRETTY_PRINT));
    }
}
