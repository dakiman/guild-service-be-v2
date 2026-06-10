<?php

declare(strict_types=1);

namespace App\Blizzard\Exceptions;

use RuntimeException;
use Throwable;

class BlizzardApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly ?int $statusCode = null,
        public readonly ?string $region = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'status_code' => $this->statusCode,
            'region' => $this->region,
        ];
    }
}
