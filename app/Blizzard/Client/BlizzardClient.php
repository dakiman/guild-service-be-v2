<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use App\Blizzard\Contracts\TokenManagerInterface;
use App\Blizzard\Exceptions\BlizzardThrottleTimeoutException;
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
            ->retry(config('blizzard.http.retry_backoff_ms', [100, 500]), 0, function (\Exception $exception, PendingRequest $request) {
                // A throttle-slot timeout already waited block_seconds; retrying
                // would block again. Fail fast so the job middleware releases.
                if ($exception instanceof BlizzardThrottleTimeoutException) {
                    return false;
                }

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
