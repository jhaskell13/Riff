<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artist>
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
            // Southern New England spread, matching the default map center,
            // so a seeded database puts visible markers on the map without
            // needing a geocoding API key.
            'lat' => fake()->latitude(40.9, 42.4),
            'lng' => fake()->longitude(-73.7, -71.1),
        ];
    }
}
