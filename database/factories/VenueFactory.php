<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Venue;
use Database\Factories\Support\Towns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented venues only, like every seeded act: never a real business.
 *
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    private const NAMES = [
        'The Lantern Room', 'Copper Tap', 'Basement Signal', 'Union Hall',
        'The Static Lounge', 'Low Ceiling', 'Ferris Street Social', 'The Wick',
        'Mill Yard Music Hall', 'Second Spin Records', 'The Bent Nail', 'Harbor & Vine',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A public venue at its town's centre, as if geocoded, so factories
        // never need the geocoding API.
        $town = Towns::random();

        return [
            'name' => fake()->randomElement(self::NAMES),
            'kind' => fake()->randomElement(['club', 'bar', 'hall', 'record_store']),
            'address_visibility' => Venue::VISIBILITY_PUBLIC,
            'street_address' => fake()->buildingNumber().' '.fake()->streetName(),
            'city' => $town['city'],
            'state' => $town['state'],
            'country' => 'USA',
            'age_policy' => fake()->randomElement(['all_ages', '21_plus', 'varies']),
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'show_on_map' => true,
            'submitted_by' => User::factory(),
        ];
    }

    /**
     * A named DIY space: recurring, but its address stays with the hosts.
     */
    public function diy(): static
    {
        return $this->state(fn () => [
            'kind' => 'diy',
            'address_visibility' => Venue::VISIBILITY_PRIVATE,
            'street_address' => null,
            'age_policy' => 'all_ages',
        ]);
    }

    public function at(string $city): static
    {
        $town = Towns::named($city);

        return $this->state(fn () => [
            'city' => $town['city'],
            'state' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
        ]);
    }
}
