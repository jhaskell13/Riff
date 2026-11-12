<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\User;
use Database\Factories\Support\Towns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A musician's "band wanted" post, active, at its town's centre (as the
 * town-level geocode would place it).
 *
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $town = Towns::random();

        return [
            'type' => 'band_wanted',
            'user_id' => User::factory(),
            'title' => 'Drummer looking for a loud band',
            'body' => 'Ten years behind a kit, own gear and a car. Into anything fast. Can rehearse weeknights.',
            'instruments' => ['drums'],
            'city' => $town['city'],
            'state' => $town['state'],
            'country' => 'USA',
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'expires_at' => now()->addDays(30),
        ];
    }

    public function in(string $city): static
    {
        $town = Towns::named($city);

        return $this->state(fn () => ['city' => $town['city'], 'state' => $town['state'], 'lat' => $town['lat'], 'lng' => $town['lng']]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['closed_at' => now()]);
    }
}
