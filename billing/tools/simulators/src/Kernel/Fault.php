<?php

namespace Mohar\Simulators\Kernel;

use InvalidArgumentException;

/**
 * One planned failure for an operation (ADR 0016 §1 FaultPlan). Transport-level faults (reset, truncate, slow body) are
 * carried out by the TLS front, so the app sees them on a real socket.
 */
final readonly class Fault
{
    public const TYPES = ['latency', 'status', 'slow_body', 'commit_then_hang', 'reset_before_commit', 'truncate', 'rate_limit', 'rotate_certificate'];

    /**
     * @param  array<string, int|string>  $params
     */
    public function __construct(public string $type, public array $params = [])
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown fault [{$type}].");
        }
    }

    public static function latency(int $milliseconds, int $jitter = 0): self
    {
        return new self('latency', ['ms' => $milliseconds, 'jitter' => $jitter]);
    }

    public static function status(int $status, string $body = ''): self
    {
        return new self('status', ['status' => $status, 'body' => $body]);
    }

    /** The body trickles out in small chunks, one every $milliseconds. */
    public static function slowBody(int $milliseconds = 200): self
    {
        return new self('slow_body', ['ms' => $milliseconds]);
    }

    /** The provider does the work, then answers only after $seconds (the client's timeout after commit). */
    public static function commitThenHang(int $seconds): self
    {
        return new self('commit_then_hang', ['seconds' => $seconds]);
    }

    /** The connection is cut before the work is done. */
    public static function resetBeforeCommit(): self
    {
        return new self('reset_before_commit');
    }

    /** The work is done, and the answer is cut off after $bytes of its body. */
    public static function truncate(int $bytes = 16): self
    {
        return new self('truncate', ['bytes' => $bytes]);
    }

    public static function rateLimit(int $retryAfter = 1): self
    {
        return new self('rate_limit', ['retry_after' => $retryAfter]);
    }

    /** The server presents a new key from this request on. */
    public static function rotateCertificate(): self
    {
        return new self('rotate_certificate');
    }

    /**
     * @param  array{type: string, params?: array<string, int|string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['params'] ?? []);
    }

    /**
     * @return array{type: string, params: array<string, int|string>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'params' => $this->params];
    }
}
