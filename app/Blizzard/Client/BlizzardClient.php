<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use App\Blizzard\Contracts\TokenManagerInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

abstract class BlizzardClient
{
    public function __construct(
        protected readonly TokenManagerInterface $tokenManager,
        protected readonly string $region = 'eu',
    ) {}

    abstract protected function namespace(): string;

    abstract protected function timeout(): int;

    protected function baseUrl(): string
    {
        return "https://{$this->region}.api.blizzard.com";
    }

    protected function request(): PendingRequest
    {
        return Http::withToken($this->tokenManager->getToken($this->region))
            ->baseUrl($this->baseUrl())
            ->withQueryParameters([
                'namespace' => $this->namespace(),
                'locale' => 'en_GB',
            ])
            ->timeout($this->timeout())
            ->connectTimeout(5)
            ->retry(3, 0, function (\Exception $exception, PendingRequest $request) {
                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();

                    // Never retry client errors (429 handled at job middleware layer)
                    if ($status >= 400 && $status < 500) {
                        return false;
                    }

                    // Retry on 5xx
                    if ($status >= 500) {
                        return true;
                    }
                }

                // Retry on timeouts (ConnectionException)
                return true;
            }, throw: true);
    }
}
