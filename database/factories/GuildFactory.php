<?php

namespace Database\Factories;

use App\Models\Guild;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Guild> */
class GuildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->slug(2),
            'realm' => fake()->slug(2),
            'region' => fake()->randomElement(['eu', 'us', 'kr', 'tw']),
            'faction' => fake()->randomElement(['Alliance', 'Horde']),
            'achievement_points' => fake()->numberBetween(0, 100000),
            'member_count' => fake()->numberBetween(1, 1000),
            'created_timestamp' => fake()->unixTime(),
            'num_of_searches' => fake()->numberBetween(0, 100),
        ];
    }
}
