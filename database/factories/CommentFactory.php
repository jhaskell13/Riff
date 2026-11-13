<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Support\AuthorContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commentable_type' => (new Post)->getMorphClass(),
            'commentable_id' => Post::factory(),
            'user_id' => User::factory(),
            'author_context' => AuthorContext::Fan,
            'artist_id' => null,
            'parent_id' => null,
            'content' => fake()->sentence(),
            'status' => Comment::PUBLISHED,
        ];
    }

    /** The band answering on its own page. */
    public function asArtist(Artist $artist, ?User $member = null): static
    {
        return $this->state(fn () => [
            'author_context' => AuthorContext::Artist,
            'artist_id' => $artist->id,
            'user_id' => $member?->getKey() ?? $artist->members()->value('users.id') ?? User::factory(),
        ]);
    }

    /** A reply to a top-level comment. One level is as deep as it goes. */
    public function replyTo(Comment $parent): static
    {
        return $this->state(fn () => [
            'commentable_type' => $parent->commentable_type,
            'commentable_id' => $parent->commentable_id,
            'parent_id' => $parent->id,
        ]);
    }
}
