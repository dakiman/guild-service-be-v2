<?php

declare(strict_types=1);

namespace Tests\Unit\Blizzard\Mappers;

use App\Blizzard\Mappers\MythicPlusRatingMapper;
use Tests\TestCase;

final class MythicPlusRatingMapperTest extends TestCase
{
    private function seasonPayload(string $memberName): array
    {
        return [
            'best_runs' => [
                [
                    'mythic_rating' => ['rating' => 549.4],
                    'members' => [
                        [
                            'character' => [
                                'name' => $memberName,
                                'realm' => ['slug' => 'howling-fjord'],
                            ],
                            'specialization' => ['id' => 252],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_display_cased_cyrillic_member_matches_canonical_want_name(): void
    {
        $result = (new MythicPlusRatingMapper)->map(
            null,
            $this->seasonPayload('Бробабади'),
            'бробабади',
            'howling-fjord',
        );

        $this->assertSame([252 => 549], $result->perSpec);
    }

    public function test_ascii_mixed_case_member_still_matches(): void
    {
        $result = (new MythicPlusRatingMapper)->map(
            null,
            $this->seasonPayload('Melaniya'),
            'melaniya',
            'howling-fjord',
        );

        $this->assertSame([252 => 549], $result->perSpec);
    }
}
