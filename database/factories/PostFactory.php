<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
use App\Support\AuthorContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // A post's voice is explicit, never inferred: the default is a
            // listener speaking as themselves, and asArtist() is how a
            // post becomes the band's.
            'author_context' => AuthorContext::Fan,
            'content' => fake()->paragraph(),
            'status' => 'published',
            'published_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    /**
     * The artist speaking as itself, written by one of its members. The
     * wall it lives on is the artist's own page.
     */
    public function asArtist(Artist $artist, ?User $member = null): static
    {
        return $this->state(fn () => [
            'artist_id' => $artist->id,
            'author_context' => AuthorContext::Artist,
            'user_id' => $member?->getKey() ?? $artist->members()->value('users.id') ?? User::factory(),
        ]);
    }

    /** A listener posting on an artist's wall, under their own name. */
    public function onWall(Artist $artist): static
    {
        return $this->state(fn () => [
            'artist_id' => $artist->id,
            'author_context' => AuthorContext::Fan,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }
}
