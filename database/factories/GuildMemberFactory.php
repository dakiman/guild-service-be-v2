<?php

namespace Database\Factories;

use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GuildMember> */
class GuildMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'guild_id' => Guild::factory(),
            'character_id' => null,
            'name' => strtolower(fake()->firstName()),
            'realm' => fake()->slug(2),
            'display_name' => null,
            'display_realm' => null,
            'level' => 80,
            'class_id' => fake()->numberBetween(1, 13),
            'race_id' => fake()->numberBetween(1, 11),
            'rank' => fake()->numberBetween(0, 9),
        ];
    }
}
