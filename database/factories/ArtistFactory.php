<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Genre;
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
        return [
            'name' => fake()->company,
            'artist_type' => fake()->randomElement(array_keys(config('riff.artist.types'))),
            'hometown' => fake()->city,
            'stateOrProvince' => fake()->stateAbbr,
            'country' => 'USA',
            'biography' => fake()->paragraph,
            // Southern New England spread, matching the default map center,
            // so a seeded database puts visible markers on the map without
            // needing a geocoding API key.
            'lat' => fake()->latitude(40.9, 42.4),
            'lng' => fake()->longitude(-73.7, -71.1),
            'show_on_map' => true,
        ];
    }

    /**
     * Attach genres drawn from whatever vocabulary exists. Real profiles
     * always carry at least one, so the default factory state should too.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Artist $artist) {
            if ($artist->genres()->exists()) {
                return;
            }

            $genres = Genre::query()->inRandomOrder()->limit(rand(1, 2))->pluck('id');

            if ($genres->isNotEmpty()) {
                $artist->genres()->sync($genres);
            }
        });
    }

    /**
     * @param  list<string>  $names
     */
    public function withGenres(array $names): static
    {
        return $this->afterCreating(function (Artist $artist) use ($names) {
            $artist->genres()->sync(Genre::whereIn('name', $names)->pluck('id'));
        });
    }

    /**
     * Real public links, used so a seeded development map has profiles with
     * a player that actually loads. Never used for pilot or imported data.
     */
    public function withMusic(): static
    {
        return $this->state(fn () => [
            'music_url' => fake()->randomElement([
                'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
                'https://open.spotify.com/album/1ATL5GLyefJaxhQzSPVrLX',
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'https://soundcloud.com/forss/flickermood',
            ]),
        ]);
    }

    public function hiddenFromMap(): static
    {
        return $this->state(fn () => ['show_on_map' => false]);
    }
}
