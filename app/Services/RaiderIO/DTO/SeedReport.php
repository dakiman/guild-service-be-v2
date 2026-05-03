<?php

declare(strict_types=1);

namespace App\Services\RaiderIO\DTO;

final class SeedReport
{
    public int $considered = 0;
    public int $dispatched = 0;
    public int $skippedTtl = 0;
    public int $skippedDedupe = 0;
    public int $errors = 0;

    public function __construct(
        public readonly string $phase,
        /** @var list<string> */
        public readonly array $regions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'regions' => $this->regions,
            'considered' => $this->considered,
            'dispatched' => $this->dispatched,
            'skipped_ttl' => $this->skippedTtl,
            'skipped_dedupe' => $this->skippedDedupe,
            'errors' => $this->errors,
        ];
    }
}
