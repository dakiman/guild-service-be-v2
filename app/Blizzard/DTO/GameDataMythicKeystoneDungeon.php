<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class GameDataMythicKeystoneDungeon
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $mediaUrl,
        public ?int $journalInstanceId,
    ) {}
}
