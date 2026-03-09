<?php

declare(strict_types=1);

namespace WbFileBrowser;

use RuntimeException;

final class MaintenanceModeException extends RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
        string $message = 'The file browser is temporarily unavailable while maintenance is in progress.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
