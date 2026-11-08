<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
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
            'genre' => 'Rock',
            'hometown' => 'Hartford',
            'stateOrProvince' => 'CT',
            'country' => 'USA',
            'lat' => 0.0,
            'lng' => 0.0,
            'image_url' => 'https://evil.example/x.png',
        ]);

        $artist = Artist::firstOrFail();
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
            'genre' => $artist->genre,
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
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
