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
            'name' => ActNames::for($type),
            'artist_type' => $type,
            'hometown' => $town['city'],
            'stateOrProvince' => $town['state'],
            'country' => 'USA',
            'biography' => ActBios::for($type, $town['city']),
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'show_on_map' => true,
            'wall_mode' => config('riff.wall.default_mode'),
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
     * A playable-shaped music link for an invented artist, so the profile
     * renders its Listen section and the map's "has music" filter has
     * something to match.
     *
     * These used to be real public tracks, which put a real musician's work
     * on an invented band's profile -- the same misattribution the dataset
     * forbids for real acts. The identifiers here are deliberately
     * all-zero: a valid *shape* (so MusicLink parses them and the embed
     * builds), but not a real track, so the player resolves to the
     * provider's own "not found" rather than to a stranger's music.
     *
     * The host is the provider itself, never an artist-owned subdomain or
     * path: an invented name is exactly the kind a real act may already
     * have taken.
     */
    public function withMusic(): static
    {
        return $this->state(fn () => [
            'music_url' => fake()->randomElement([
                // 22-character base62 ids, Spotify's own shape.
                'https://open.spotify.com/track/0000000000000000000000',
                'https://open.spotify.com/album/0000000000000000000000',
                // 11 characters, YouTube's own shape.
                'https://www.youtube.com/watch?v=00000000000',
            ]),
        ]);
    }

    public function hiddenFromMap(): static
    {
        return $this->state(fn () => ['show_on_map' => false]);
    }
}
