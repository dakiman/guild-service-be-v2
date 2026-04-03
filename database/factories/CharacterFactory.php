<?php

namespace Database\Factories;

use App\Models\Character;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Character> */
class CharacterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => strtolower(fake()->firstName()),
            'realm' => fake()->slug(2),
            'region' => fake()->randomElement(['eu', 'us', 'kr', 'tw']),
            'gender' => fake()->randomElement(['Male', 'Female']),
            'faction' => fake()->randomElement(['Alliance', 'Horde']),
            'race_id' => fake()->numberBetween(1, 30),
            'class_id' => fake()->numberBetween(1, 13),
            'level' => 80,
            'achievement_points' => fake()->numberBetween(0, 50000),
            'average_item_level' => fake()->numberBetween(400, 650),
            'equipped_item_level' => fake()->numberBetween(400, 650),
            'active_specialization' => fake()->randomElement(['Frost', 'Fire', 'Arcane', 'Holy', 'Protection', 'Arms']),
            'media' => [
                'avatar' => 'https://render.worldofwarcraft.com/us/character/avatar.jpg',
                'inset' => 'https://render.worldofwarcraft.com/us/character/inset.jpg',
                'main' => 'https://render.worldofwarcraft.com/us/character/main.jpg',
            ],
            'num_of_searches' => fake()->numberBetween(0, 100),
            'recruitment' => false,
        ];
    }
}
