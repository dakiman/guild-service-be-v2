<?php

namespace Database\Factories;

use App\Models\DungeonRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DungeonRun> */
class DungeonRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season' => fake()->numberBetween(13, 15),
            'dungeon_id' => fake()->numberBetween(100, 500),
            'dungeon_name' => fake()->randomElement([
                'The Stonevault', 'City of Threads', 'Ara-Kara', 'The Dawnbreaker',
                'Mists of Tirna Scithe', 'The Necrotic Wake', 'Siege of Boralus',
            ]),
            'keystone_level' => fake()->numberBetween(2, 30),
            'duration' => fake()->numberBetween(900000, 3600000),
            'completed_timestamp' => fake()->unixTime() * 1000,
            'is_completed_on_time' => fake()->boolean(70),
            'affixes' => [
                ['id' => 9, 'name' => 'Tyrannical'],
                ['id' => 7, 'name' => 'Bolstering'],
                ['id' => 124, 'name' => 'Bursting'],
            ],
        ];
    }
}
