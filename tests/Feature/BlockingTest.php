<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blocking is one listener's own view of Riff: the artist leaves their map
 * and their feed, and nobody else's view changes at all.
 */
class BlockingTest extends TestCase
{
    use RefreshDatabase;

    private const BOUNDS = [
        'south' => 41.0,
        'north' => 42.0,
        'west' => -73.0,
        'east' => -72.0,
    ];

    private function visibleArtist(array $attributes = []): Artist
    {
        return Artist::factory()->create([
            'lat' => 41.5,
            'lng' => -72.5,
            'show_on_map' => true,
            ...$attributes,
        ]);
    }

    public function test_a_listener_can_block_and_unblock_an_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->post(route('artists.block', $artist))->assertRedirect();
        $this->assertTrue($user->fresh()->blocks($artist));

        $this->actingAs($user)->delete(route('artists.unblock', $artist))->assertRedirect();
        $this->assertFalse($user->fresh()->blocks($artist));
    }

    public function test_blocking_twice_is_harmless(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->post(route('artists.block', $artist));
        $this->actingAs($user)->post(route('artists.block', $artist));

        $this->assertDatabaseCount('artist_blocks', 1);
    }

    public function test_blocking_an_artist_stops_following_them(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        $user->followedArtists()->attach($artist);

        $this->actingAs($user)->post(route('artists.block', $artist));

        $this->assertFalse($user->fresh()->follows($artist));
    }

    public function test_members_cannot_block_their_own_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        $artist->members()->attach($user);

        $this->actingAs($user)->post(route('artists.block', $artist))->assertForbidden();

        $this->assertDatabaseCount('artist_blocks', 0);
    }

    public function test_guests_cannot_block(): void
    {
        $artist = Artist::factory()->create();

        $this->post(route('artists.block', $artist))->assertRedirect(route('login'));
        $this->assertDatabaseCount('artist_blocks', 0);
    }

    public function test_a_blocked_artist_leaves_the_blockers_map(): void
    {
        $user = User::factory()->create();
        $blocked = $this->visibleArtist(['name' => 'Hidden Act']);
        $kept = $this->visibleArtist(['name' => 'Still Here']);

        $user->blockedArtists()->attach($blocked);

        $names = collect($this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains('Still Here'));
        $this->assertFalse($names->contains('Hidden Act'));
    }

    public function test_a_block_changes_nothing_for_anyone_else(): void
    {
        $blocker = User::factory()->create();
        $other = User::factory()->create();
        $blocked = $this->visibleArtist(['name' => 'Hidden Act']);

        $blocker->blockedArtists()->attach($blocked);

        // A guest first: actingAs would stay in force for the rest of the
        // test, so the unauthenticated case has to come before it.
        $this->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Hidden Act');

        $this->actingAs($other)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Hidden Act');
    }

    /**
     * Blocked artists must not come back through a cached copy of someone
     * else's results, and a listener who blocks nobody must keep using the
     * shared cache.
     */
    public function test_results_are_not_shared_between_a_blocker_and_everyone_else(): void
    {
        $blocker = User::factory()->create();
        $other = User::factory()->create();
        $blocked = $this->visibleArtist(['name' => 'Hidden Act']);
        $blocker->blockedArtists()->attach($blocked);

        // Warm the shared cache as a guest first.
        $this->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('meta.returned', 1);

        $this->actingAs($blocker)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('meta.returned', 0);

        // The blocker's own results are never cached, so they cannot become
        // the next listener's -- who blocks nobody and reads the shared copy.
        $this->actingAs($other)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('meta.returned', 1)
            ->assertJsonPath('data.0.name', 'Hidden Act');
    }

    /**
     * Only a listener who actually blocks someone pays the cost of
     * uncacheable results; that is what keeps the exclusion affordable.
     */
    public function test_only_a_blockers_own_results_become_uncacheable(): void
    {
        $plain = User::factory()->create();
        $blocker = User::factory()->create();
        $artist = $this->visibleArtist();
        $blocker->blockedArtists()->attach($artist);

        $this->actingAs($plain)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=30, private');

        $this->actingAs($blocker)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cluster_counts_exclude_blocked_artists(): void
    {
        config(['riff.map.max_markers' => 2]);

        $user = User::factory()->create();
        Artist::factory()->count(3)->create(['lat' => 41.5, 'lng' => -72.5, 'show_on_map' => true]);
        $blocked = $this->visibleArtist();
        $user->blockedArtists()->attach($blocked);

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonPath('meta.mode', 'clusters')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_a_blocked_artists_posts_leave_the_blockers_feed(): void
    {
        $user = User::factory()->create();
        $blocked = Artist::factory()->create();

        Post::factory()->create(['artist_id' => $blocked->id, 'content' => 'From the blocked artist']);
        Post::factory()->create(['artist_id' => null, 'content' => 'From a person with no artist']);
        Post::factory()->create(['artist_id' => Artist::factory(), 'content' => 'From someone else']);

        $user->blockedArtists()->attach($blocked);

        $contents = collect($this->actingAs($user)
            ->get(route('feed.index'))
            ->assertOk()
            ->viewData('page')['props']['posts']['data'])
            ->pluck('content');

        $this->assertFalse($contents->contains('From the blocked artist'));
        // A post with no artist behind it has nobody blockable; NOT IN on a
        // null column would have dropped it.
        $this->assertTrue($contents->contains('From a person with no artist'));
        $this->assertTrue($contents->contains('From someone else'));
    }

    public function test_blocked_artists_are_listed_on_the_profile_page(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['name' => 'Hidden Act']);
        $user->blockedArtists()->attach($artist);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->where('blockedArtists.0.name', 'Hidden Act'));
    }

    public function test_the_map_card_says_whether_the_viewer_has_blocked_the_artist(): void
    {
        $user = User::factory()->create();
        $artist = $this->visibleArtist();
        $user->blockedArtists()->attach($artist);

        $this->actingAs($user)
            ->getJson(route('map.artists.card', $artist))
            ->assertOk()
            ->assertJsonPath('data.isBlocked', true)
            ->assertJsonPath('data.canBlock', true);
    }

    public function test_blocking_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $artists = Artist::factory()->count(61)->create();

        foreach ($artists as $index => $artist) {
            $response = $this->actingAs($user)->post(route('artists.block', $artist));

            if ($index < 60) {
                $response->assertRedirect();
            } else {
                $response->assertTooManyRequests();
            }
        }
    }
}
