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

    public function test_season_id_is_the_newest_season_in_the_base_profile(): void
    {
        $result = (new MythicPlusRatingMapper)->map(
            [
                'current_mythic_rating' => ['rating' => 2723.49, 'color' => ['r' => 163, 'g' => 53, 'b' => 238]],
                'seasons' => [
                    ['key' => ['href' => 'https://x/15'], 'id' => 15],
                    ['key' => ['href' => 'https://x/17'], 'id' => 17],
                ],
            ],
            null,
            'melaniya',
            'the-maelstrom',
        );

        $this->assertSame(2723, $result->rating);
        $this->assertSame('#a335ee', $result->color);
        $this->assertSame(17, $result->seasonId);
    }

    public function test_season_id_is_null_when_the_profile_has_no_seasons(): void
    {
        $this->assertNull((new MythicPlusRatingMapper)->map(['seasons' => []], null, 'x', 'y')->seasonId);
        $this->assertNull((new MythicPlusRatingMapper)->map(['current_mythic_rating' => null], null, 'x', 'y')->seasonId);
        $this->assertNull((new MythicPlusRatingMapper)->map(null, null, 'x', 'y')->seasonId);
    }
}
