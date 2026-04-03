<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withBattleNet(): static
    {
        return $this->state(fn (array $attributes) => [
            'bnet_id' => fake()->numberBetween(10000, 99999),
            'bnet_tag' => fake()->userName().'#'.fake()->numberBetween(1000, 9999),
            'bnet_region' => fake()->randomElement(['eu', 'us', 'kr', 'tw']),
            'bnet_synced_at' => now(),
        ]);
    }
}
