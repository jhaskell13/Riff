<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $genres = ['Rock', 'Metal', 'Pop', 'Indy', 'Jazz'];

        return [
            'name' => fake()->company,
            'hometown' => fake()->city,
            'stateOrProvince' => fake()->stateAbbr,
            'country' => 'USA',
            'genre' => fake()->randomElement($genres),
        ];
    }
}
