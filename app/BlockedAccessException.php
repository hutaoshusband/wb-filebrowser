<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;

final class BlockedAccessException extends RuntimeException
{
    public function __construct(
        private readonly string $source,
        private readonly ?string $blockedUntil = null,
        private readonly bool $blockedPermanently = false,
        private readonly ?int $retryAfterSeconds = null,
        string $message = 'You have been blocked.'
    ) {
        parent::__construct($message);
    }

    public static function temporary(string $source, int $retryAfterSeconds, string $message = 'You have been blocked.'): self
    {
        $seconds = max(1, $retryAfterSeconds);

        return new self(
            $source,
            gmdate('c', time() + $seconds),
            false,
            $seconds,
            $message
        );
    }

    public static function permanent(string $source, string $message = 'You have been blocked.'): self
    {
        return new self($source, null, true, null, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'source' => $this->source,
            'blocked_until' => $this->blockedUntil,
            'blocked_permanently' => $this->blockedPermanently,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
