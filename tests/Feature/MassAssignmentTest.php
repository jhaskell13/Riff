<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\Post;
use App\Models\User;
use App\Support\AuthorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Controllers persist only validated data, so unexpected request fields
 * must never reach the database even when the attribute is fillable.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_store_ignores_injected_coordinates_and_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.store'), [
            'name' => 'Honest Band',
            'artist_type' => 'band',
            'genres' => [Genre::firstOrFail()->id],
            'hometown' => 'Hartford',
            'stateOrProvince' => 'CT',
            'country' => 'USA',
            'show_on_map' => true,
            'lat' => 0.0,
            'lng' => 0.0,
            'image_path' => 'artists/evil.png',
            'image_url' => 'https://evil.example/x.png',
        ]);

        $artist = Artist::firstOrFail();
        // Photos are only ever written by the upload pipeline.
        $this->assertNull($artist->image_path);
        $this->assertNull($artist->image_url);
        // Coordinates come from geocoding, never from client input.
        $this->assertNull($artist->lat);
    }

    public function test_artist_update_ignores_injected_coordinates(): void
    {
        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);
        $member = User::factory()->create();
        $artist->members()->attach($member);

        $this->actingAs($member)->patch(route('artists.update', $artist), [
            'name' => $artist->name,
            'artist_type' => $artist->artist_type,
            'genres' => $artist->genres->pluck('id')->all(),
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
            'show_on_map' => true,
            'lat' => 0.0,
            'lng' => 0.0,
        ]);

        $this->assertSame(41.5, $artist->fresh()->lat);
    }

    public function test_feed_store_ignores_injected_status_and_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->post(route('feed.store'), [
            'content' => 'Legit content',
            'status' => 'draft',
            'artist_id' => $artist->id,
            'published_at' => '1999-01-01 00:00:00',
        ]);

        $post = Post::firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertNull($post->artist_id);
        $this->assertTrue($post->published_at->isCurrentDay());
    }

    public function test_wall_post_ignores_injected_voice_and_author(): void
    {
        // author_context is fillable but never a validation rule, so
        // ...validated() cannot carry it: App\Services\Wall decides the
        // voice from the authorization result. A request that could pick
        // its own voice would be an endpoint for speaking as the band.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $user = User::factory()->create();
        $impostor = User::factory()->create();

        $this->actingAs($user)
            ->post(route('wall.posts.store', $artist), [
                'content' => 'Hello',
                'author_context' => 'artist',
                'user_id' => $impostor->id,
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors();

        $post = Post::sole();

        $this->assertSame(AuthorContext::Fan, $post->author_context);
        $this->assertSame($user->id, $post->user_id);
        $this->assertSame('published', $post->status);
    }

    public function test_comment_ignores_injected_voice_and_artist(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $member = User::factory()->create();
        $artist->members()->attach($member);
        $post = Post::factory()->asArtist($artist, $member)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('wall.comments.store', $post), [
                'content' => 'Hello',
                'author_context' => 'artist',
                'artist_id' => $artist->id,
                'status' => 'hidden',
            ])
            ->assertSessionHasNoErrors();

        $comment = Comment::sole();

        $this->assertSame(AuthorContext::Fan, $comment->author_context);
        // Null, not the artist: a fan's reply must never be able to
        // render as the band.
        $this->assertNull($comment->artist_id);
        $this->assertSame(Comment::PUBLISHED, $comment->status);
    }

    public function test_stream_store_ignores_injected_status(): void
    {
        $artist = Artist::factory()->create();
        $member = User::factory()->create();
        $artist->members()->attach($member);

        $this->actingAs($member)->post(route('stream.store'), [
            'artist_id' => $artist->id,
            'title' => 'Legit stream',
            'status' => 'live',
            'viewer_count' => 9999,
        ]);

        $this->assertDatabaseHas('live_streams', [
            'title' => 'Legit stream',
            'status' => 'scheduled',
            'viewer_count' => 0,
        ]);
    }

    public function test_profile_update_ignores_injected_coordinates(): void
    {
        $user = User::factory()->create(['lat' => 41.5, 'lng' => -72.5]);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'city' => $user->city,
            'state' => $user->state,
            'country' => $user->country,
            'lat' => 0.0,
            'lng' => 0.0,
        ]);

        $this->assertSame(41.5, $user->fresh()->lat);
    }
}
