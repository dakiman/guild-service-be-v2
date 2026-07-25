<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\GuildRosterMapper;
use Tests\TestCase;

final class GuildRosterMapperTest extends TestCase
{
    private function rosterPayload(string $name): array
    {
        return [
            'members' => [
                [
                    'character' => [
                        'name' => $name,
                        'realm' => ['slug' => 'howling-fjord', 'name' => 'Howling Fjord'],
                        'level' => 90,
                        'playable_class' => ['id' => 6],
                        'playable_race' => ['id' => 86],
                    ],
                    'rank' => 3,
                ],
            ],
        ];
    }

    public function test_cyrillic_member_name_is_canonicalized_mb_lowercase(): void
    {
        $members = (new GuildRosterMapper)->map($this->rosterPayload('Бробабади'));

        $this->assertSame('бробабади', $members[0]->name);
        $this->assertSame('Бробабади', $members[0]->displayName);
    }

    public function test_ascii_member_name_still_lowercases(): void
    {
        $members = (new GuildRosterMapper)->map($this->rosterPayload('Melaniya'));

        $this->assertSame('melaniya', $members[0]->name);
        $this->assertSame('Melaniya', $members[0]->displayName);
    }
}
