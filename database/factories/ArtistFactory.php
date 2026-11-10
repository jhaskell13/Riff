<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Genre;
use Database\Factories\Support\ActBios;
use Database\Factories\Support\ActNames;
use Database\Factories\Support\Towns;
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
        // A real town at its real center, matching what the geocoder would
        // store, so a seeded map works without a geocoding API key and no
        // pin lands in the water.
        $town = Towns::random();
        $type = fake()->randomElement(array_keys(config('riff.artist.types')));

        return [
            // Names and bios that read like a local scene rather than a
            // business directory; the seeded map is what Riff is shown
            // with, so it has to look like the real thing.
            'name' => ActNames::for($type),
            'artist_type' => $type,
            'hometown' => $town['city'],
            'stateOrProvince' => $town['state'],
            'country' => 'USA',
            'biography' => ActBios::for($type, $town['city']),
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'show_on_map' => true,
        ];
    }

    public function inTown(string $city): static
    {
        $town = Towns::named($city);

        return $this->state(fn () => [
            'hometown' => $town['city'],
            'stateOrProvince' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
        ]);
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
