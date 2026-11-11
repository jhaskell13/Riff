<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use Database\Factories\Support\Towns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A member-listed show at a venue, a week or two out, with its host on
 * the bill (confirmed), as a real listing always has.
 *
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_artist_id' => Artist::factory(),
            'created_by' => User::factory(),
            'venue_id' => Venue::factory(),
            'starts_at' => now()->addDays(fake()->numberBetween(3, 20))->setTime(1, 0),
            'timezone' => 'America/New_York',
            'price' => fake()->randomElement(['$10', '$12 adv / $15 door', 'Free', 'PWYC']),
            'source' => Show::SOURCE_MEMBER,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Show $show) {
            if ($show->host_artist_id === null) {
                return;
            }

            $entry = $show->lineup()->make(['artist_id' => $show->host_artist_id, 'position' => 0]);
            $entry->forceFill(['status' => LineupEntry::CONFIRMED, 'responded_at' => now()])->save();
        });
    }

    /**
     * Somewhere that isn't a venue: a label, a town, a note. Coordinates
     * as the town-level geocode would set them.
     */
    public function oneOff(string $city = 'Hartford'): static
    {
        $town = Towns::named($city);

        return $this->state(fn () => [
            'venue_id' => null,
            'location_name' => 'Backyard on the west side',
            'city' => $town['city'],
            'state' => $town['state'],
            'country' => 'USA',
            'location_note' => 'Ask a punk',
            'lat' => $town['lat'],
            'lng' => $town['lng'],
        ]);
    }

    public function past(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subDays(3)]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['cancelled_at' => now()]);
    }
}
