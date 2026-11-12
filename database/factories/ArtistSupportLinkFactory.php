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
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'artist_id' => Artist::factory(),
            'type' => 'bandcamp',
            'url' => "https://{$slug}.bandcamp.com",
            'label' => null,
            'position' => 0,
            'clicks' => 0,
        ];
    }

    public function tipJar(): static
    {
        return $this->state(fn () => [
            'type' => 'tip_jar',
            'url' => 'https://ko-fi.com/'.$this->faker->unique()->slug(1),
        ]);
    }

    /** Named ownStore, not store: Factory::store() already exists. */
    public function ownStore(): static
    {
        return $this->state(fn () => [
            'type' => 'store',
            'url' => 'https://'.$this->faker->unique()->domainName().'/shop',
        ]);
    }
}
