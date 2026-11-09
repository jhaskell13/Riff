<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistFollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_follow(): void
    {
        $artist = Artist::factory()->create();

        $this->post(route('artists.follow', $artist))->assertRedirect(route('login'));

        $this->assertDatabaseCount('artist_follows', 0);
    }

    public function test_unverified_users_cannot_follow(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('artists.follow', $artist))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('artist_follows', 0);
    }

    public function test_a_listener_can_follow_and_unfollow_an_artist(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.follow', $artist));
        $this->assertTrue($user->fresh()->follows($artist));

        $this->actingAs($user)->delete(route('artists.unfollow', $artist));
        $this->assertFalse($user->fresh()->follows($artist));
    }

    /**
     * A listener tapping follow twice -- or a retried request -- must not
     * create a second row or trip the unique constraint.
     */
    public function test_following_twice_is_idempotent(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.follow', $artist));
        $this->actingAs($user)->post(route('artists.follow', $artist))->assertRedirect();

        $this->assertDatabaseCount('artist_follows', 1);
    }

    public function test_unfollowing_something_not_followed_is_harmless(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('artists.unfollow', $artist))->assertRedirect();

        $this->assertDatabaseCount('artist_follows', 0);
    }

    public function test_an_archived_artist_cannot_be_followed(): void
    {
        $artist = Artist::factory()->create();
        $artist->delete();

        $this->actingAs(User::factory()->create())
            ->post(route('artists.follow', $artist))
            ->assertNotFound();

        $this->assertDatabaseCount('artist_follows', 0);
    }

    public function test_a_follow_does_not_grant_membership(): void
    {
        $artist = Artist::factory()->create(['name' => 'Untouched']);
        $follower = User::factory()->create();

        $this->actingAs($follower)->post(route('artists.follow', $artist));

        $this->assertFalse($follower->fresh()->isMemberOf($artist));

        $this->actingAs($follower)
            ->get(route('artists.edit', $artist))
            ->assertForbidden();
    }

    public function test_the_profile_reports_follow_state_and_count(): void
    {
        $artist = Artist::factory()->create();
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        $other->followedArtists()->attach($artist);

        $this->actingAs($viewer)
            ->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('isFollowing', false)
                ->where('artist.followers_count', 1)
            );

        $viewer->followedArtists()->attach($artist);

        $this->actingAs($viewer)
            ->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('isFollowing', true)
                ->where('artist.followers_count', 2)
            );
    }

    public function test_deleting_a_user_removes_their_follows(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        $user->delete();

        $this->assertDatabaseCount('artist_follows', 0);
    }
}
