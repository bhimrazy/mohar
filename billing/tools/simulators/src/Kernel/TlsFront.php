<?php

namespace Mohar\Simulators\Kernel;

/**
 * A TLS terminator in plain PHP (ADR 0016 §1): accepts TLS on the scenario's API port with the scenario's current leaf
 * certificate (read per connection, so a rotation takes effect at once), passes each request to the `php -S` backend,
 * and carries out the transport faults the backend asks for in `X-Sim-*` response headers:
 * `X-Sim-Reset` (cut the connection without an answer), `X-Sim-Truncate: <bytes>` (cut the body short) and
 * `X-Sim-Slow: <ms>` (trickle the body). One process, non-blocking, any number of connections.
 */
final class TlsFront
{
    private const MAX_REQUEST_BYTES = 8 * 1024 * 1024;

    private const CONNECTION_SECONDS = 180;

    private const SLOW_CHUNK_BYTES = 64;

    /** @var array<int, array{client: resource, state: string, in: string, backend: resource|null, response: string, out: string, slow: int, next: float, started: float}> */
    private array $connections = [];

    private bool $running = true;

    public function __construct(private readonly string $leafPath, private readonly int $port, private readonly int $backendPort) {}

    public function run(): void
    {
        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}", $errorCode, $errorMessage);

        if ($server === false) {
            throw new \RuntimeException("The TLS front cannot listen on {$this->port}: {$errorMessage}");
        }

        stream_set_blocking($server, false);

        while ($this->running) {
            $read = [$server];
            $write = [];

            foreach ($this->connections as $connection) {
                match ($connection['state']) {
                    'handshake', 'request' => $read[] = $connection['client'],
                    'backend' => $read[] = $connection['backend'],
                    'write' => $connection['next'] <= microtime(true) ? $write[] = $connection['client'] : null,
                    default => null,
                };
            }

            $except = null;

            if (@stream_select($read, $write, $except, 0, 50_000) === false) {
                continue;
            }

            if (in_array($server, $read, true)) {
                $this->accept($server);
            }

            foreach (array_keys($this->connections) as $id) {
                $this->step($id);
            }
        }
    }

    /** End the loop after the current pass (tests, embedding). */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @param  resource  $server
     */
    private function accept($server): void
    {
        $client = @stream_socket_accept($server, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        stream_context_set_option($client, 'ssl', 'local_cert', $this->leafPath);
        stream_context_set_option($client, 'ssl', 'verify_peer', false);
        $this->connections[(int) $client] = ['client' => $client, 'state' => 'handshake', 'in' => '', 'backend' => null, 'response' => '', 'out' => '', 'slow' => 0, 'next' => 0.0, 'started' => microtime(true)];
    }

    private function step(int $id): void
    {
        $connection = &$this->connections[$id];

        if (microtime(true) - $connection['started'] > self::CONNECTION_SECONDS) {
            $this->close($id);

            return;
        }

        if ($connection['state'] === 'handshake') {
            $done = @stream_socket_enable_crypto($connection['client'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);

            if ($done === false) {
                $this->close($id);
            } elseif ($done === true) {
                $connection['state'] = 'request';
            }

            return;
        }

        if ($connection['state'] === 'request') {
            $chunk = @fread($connection['client'], 65536);

            if ($chunk === false || ($chunk === '' && feof($connection['client']))) {
                $this->close($id);

                return;
            }

            $connection['in'] .= $chunk;

            if ($this->requestComplete($connection['in'])) {
                $this->forward($id);
            } elseif (strlen($connection['in']) > self::MAX_REQUEST_BYTES) {
                $this->close($id);
            }

            return;
        }

        if ($connection['state'] === 'backend' && $connection['backend'] !== null) {
            $chunk = @fread($connection['backend'], 65536);
            $connection['response'] .= (string) $chunk;

            if (feof($connection['backend'])) {
                fclose($connection['backend']);
                $connection['backend'] = null;
                $this->answer($id);
            }

            return;
        }

        if ($connection['state'] === 'write' && $connection['next'] <= microtime(true)) {
            $length = $connection['slow'] > 0 ? self::SLOW_CHUNK_BYTES : strlen($connection['out']);
            $written = @fwrite($connection['client'], substr($connection['out'], 0, $length));
            $connection['out'] = substr($connection['out'], max(0, (int) $written));
            $connection['next'] = $connection['slow'] > 0 ? microtime(true) + $connection['slow'] / 1000 : 0.0;

            if ($written === false || $connection['out'] === '') {
                $this->close($id);
            }
        }
    }

    private function requestComplete(string $request): bool
    {
        $end = strpos($request, "\r\n\r\n");

        if ($end === false) {
            return false;
        }

        $length = preg_match('/^content-length:\s*(\d+)/im', substr($request, 0, $end), $match) === 1 ? (int) $match[1] : 0;

        return strlen($request) - $end - 4 >= $length;
    }

    private function forward(int $id): void
    {
        $connection = &$this->connections[$id];
        $backend = @stream_socket_client("tcp://127.0.0.1:{$this->backendPort}", $errorCode, $errorMessage, 5);

        if ($backend === false) {
            $this->close($id);

            return;
        }

        // One request per connection: the backend closes after answering, which marks the end of its response.
        $request = (string) preg_replace('/^connection:[^\r\n]*\r\n/im', '', $connection['in']);
        $request = (string) preg_replace('/\r\n/', "\r\nConnection: close\r\n", $request, 1);
        fwrite($backend, $request);
        stream_set_blocking($backend, false);
        $connection['backend'] = $backend;
        $connection['state'] = 'backend';
    }

    private function answer(int $id): void
    {
        $connection = &$this->connections[$id];
        [$head, $body] = array_pad(explode("\r\n\r\n", $connection['response'], 2), 2, '');
        $controls = [];
        $lines = [];

        foreach (explode("\r\n", $head) as $line) {
            if (preg_match('/^x-sim-([a-z]+):\s*(.*)$/i', $line, $match) === 1) {
                $controls[strtolower($match[1])] = trim($match[2]);
            } else {
                $lines[] = $line;
            }
        }

        if (isset($controls['reset'])) {
            $this->close($id);

            return;
        }

        if (isset($controls['truncate'])) {
            $body = substr($body, 0, (int) $controls['truncate']);
        }

        $connection['out'] = implode("\r\n", $lines)."\r\n\r\n".$body;
        $connection['slow'] = (int) ($controls['slow'] ?? 0);
        $connection['state'] = 'write';
    }

    private function close(int $id): void
    {
        $connection = $this->connections[$id];

        if ($connection['backend'] !== null) {
            @fclose($connection['backend']);
        }

        @fclose($connection['client']);
        unset($this->connections[$id]);
    }
}
