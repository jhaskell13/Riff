<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\ArtistSupportLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtistSupportLink>
 */
class ArtistSupportLinkFactory extends Factory
{
    protected $model = ArtistSupportLink::class;

    /**
     * @return array<string, mixed>
     */
    /**
     * Fixture URLs never point anywhere a stranger could own: reserved
     * example.* domains where the type takes any host, and the service's
     * own homepage where it requires a known one. A generated band-name
     * subdomain is exactly the kind a real act may already have taken.
     */
    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'type' => 'bandcamp',
            'url' => 'https://bandcamp.com',
            'label' => null,
            'position' => 0,
            'clicks' => 0,
        ];
    }

    public function tipJar(): static
    {
        return $this->state(fn () => [
            'type' => 'tip_jar',
            'url' => 'https://ko-fi.com',
        ]);
    }

    /** Named ownStore, not store: Factory::store() already exists. */
    public function ownStore(): static
    {
        return $this->state(fn () => [
            'type' => 'store',
            'url' => 'https://example.com/shop/'.$this->faker->unique()->slug(2),
        ]);
    }
}
