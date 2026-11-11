<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('feed.index'))->assertRedirect(route('login'));
    }

    public function test_the_feed_shows_published_posts_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Post::factory()->create(['published_at' => now()->subDay()]);
        $newer = Post::factory()->create(['published_at' => now()->subHour()]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Feed')
                ->has('posts.data', 2)
                ->where('posts.data.0.id', $newer->id)
                ->where('posts.data.1.id', $older->id)
            );
    }

    public function test_draft_posts_are_not_shown(): void
    {
        $user = User::factory()->create();
        Post::factory()->draft()->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertInertia(fn ($page) => $page->has('posts.data', 0));
    }

    public function test_posts_include_their_author(): void
    {
        $user = User::factory()->create(['name' => 'Poster Name']);
        Post::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.user.name', 'Poster Name')
            );
    }

    /**
     * image_url is an accessor over image_path; eager-loading must select
     * the column, not the accessor. SQLite masked the regression by
     * treating the unknown identifier as a string literal.
     */
    public function test_posts_carry_the_artists_photo(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        $artist->forceFill(['image_path' => 'artists/1/photo.jpg'])->save();
        Post::factory()->create(['artist_id' => $artist->id]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.artist.image_url', '/storage/artists/1/photo.jpg')
            );
    }

    public function test_the_feed_is_paginated(): void
    {
        $user = User::factory()->create();
        Post::factory()->count(16)->for($user)->create();

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 15)
                ->where('posts.meta.total', 16)
            );
    }

    public function test_a_user_can_create_a_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('feed.store'), ['content' => 'Hello, Riff!'])
            ->assertRedirect(route('feed.index'));

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'content' => 'Hello, Riff!',
            'status' => 'published',
        ]);

        $this->assertNotNull(Post::firstOrFail()->published_at);
    }

    public function test_post_content_is_required_and_bounded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('feed.store'), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->actingAs($user)
            ->post(route('feed.store'), ['content' => str_repeat('a', 5001)])
            ->assertSessionHasErrors('content');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_a_post_cannot_be_created_for_another_user(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->post(route('feed.store'), [
            'content' => 'Spoofed',
            'user_id' => $victim->id,
        ]);

        $this->assertDatabaseHas('posts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('posts', ['user_id' => $victim->id]);
    }

    public function test_artist_posts_surface_the_artist_name(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['name' => 'The Testers']);
        Post::factory()->for($user)->create(['artist_id' => $artist->id]);

        $this->actingAs($user)
            ->get(route('feed.index'))
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.artist.name', 'The Testers')
            );
    }
}
