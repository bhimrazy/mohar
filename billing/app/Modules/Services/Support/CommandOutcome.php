<?php

namespace App\Modules\Services\Support;

/**
 * Result of executing one module command attempt.
 */
final readonly class CommandOutcome
{
    public const SUCCEEDED = 'succeeded';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const QUEUED = 'queued';

    public function __construct(
        public string $status,
        public ?string $message = null,
        public int $retryDelay = 0,
    ) {}

    public static function succeeded(?string $message = null): self
    {
        return new self(self::SUCCEEDED, $message);
    }

    public static function retry(string $message, int $delay): self
    {
        return new self(self::RETRY, $message, $delay);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(self::SKIPPED, $message);
    }

    public static function queued(string $message): self
    {
        return new self(self::QUEUED, $message);
    }

    public function succeededNow(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
