<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Database\Factories\Support\Towns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Your scene": the signed-in home that takes the nav slot the global feed
 * used to hold.
 *
 * The rules under test are the ones that keep it from becoming the thing
 * it replaced: it is scoped to who you follow and where you are, ordered
 * by date and distance, and never ranked.
 */
class YourSceneTest extends TestCase
{
    use RefreshDatabase;

    private function hartfordUser(): User
    {
        $town = Towns::named('Hartford');

        return User::factory()->create([
            'city' => $town['city'],
            'state' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
        ]);
    }

    private function artistIn(string $town, string $name): Artist
    {
        $place = Towns::named($town);

        return Artist::factory()->create([
            'name' => $name,
            'hometown' => $place['city'],
            'stateOrProvince' => $place['state'],
            'lat' => $place['lat'],
            'lng' => $place['lng'],
        ]);
    }

    private function update(Artist $artist, string $content, ?string $at = null): Post
    {
        $member = User::factory()->create();
        $artist->members()->attach($member);

        return Post::factory()->asArtist($artist, $member)->create([
            'content' => $content,
            'published_at' => $at === null ? now() : now()->parse($at),
        ]);
    }

    // ---------------------------------------------------------------
    // Scope
    // ---------------------------------------------------------------

    #[Test]
    public function it_shows_updates_from_artists_you_follow_and_nobody_else(): void
    {
        $user = $this->hartfordUser();
        $followed = $this->artistIn('Hartford', 'Followed Act');
        $stranger = $this->artistIn('Hartford', 'Stranger Act');

        $user->followedArtists()->attach($followed);

        $this->update($followed, 'We have a new record');
        $this->update($stranger, 'So do we');

        $this->actingAs($user)->get(route('scene.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('updates.data', 1)
                ->where('updates.data.0.content', 'We have a new record'));
    }

    #[Test]
    public function fan_posts_on_a_followed_wall_are_not_updates(): void
    {
        // Following a band is not subscribing to everyone who writes on
        // their page. Pulling fan posts in would make this the stream of
        // strangers the global feed already is.
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        Post::factory()->onWall($artist)->create([
            'user_id' => User::factory()->create()->id,
            'content' => 'A fan said this',
        ]);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page->has('updates.data', 0));
    }

    #[Test]
    public function it_shows_when_the_artists_you_follow_next_play(): void
    {
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $show = Show::factory()->for($artist, 'hostArtist')->create([
            'starts_at' => now()->addDays(5),
        ]);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page
                ->has('shows', 1)
                ->where('shows.0.id', $show->id));
    }

    #[Test]
    public function it_shows_whats_on_near_you_whoever_is_playing(): void
    {
        // The other half of the page: a place, not a follow list.
        $user = $this->hartfordUser();
        $local = $this->artistIn('Hartford', 'Local Act');

        // oneOff() places the show in a town. The nearby half is a map
        // search, so a show with no point is on no map and in no scene.
        Show::factory()->for($local, 'hostArtist')->oneOff('Hartford')
            ->create(['starts_at' => now()->addDays(4)]);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page
                ->has('nearby.shows', 1)
                ->where('followingCount', 0));
    }

    #[Test]
    public function a_hidden_update_never_reaches_your_scene(): void
    {
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $post = $this->update($artist, 'Hidden words');
        $post->forceFill(['status' => Post::HIDDEN])->save();

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page->has('updates.data', 0))
            ->assertDontSee('Hidden words');
    }

    #[Test]
    public function a_future_dated_update_waits_its_turn(): void
    {
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        Post::factory()->asArtist($artist)->create([
            'content' => 'Scheduled',
            'published_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page->has('updates.data', 0));
    }

    // ---------------------------------------------------------------
    // Ordering: the reason this page exists
    // ---------------------------------------------------------------

    #[Test]
    public function updates_are_ordered_by_date_and_never_by_likes(): void
    {
        // ROADMAP principle 2. A new band's post is as visible here as a
        // popular one's -- this page replaces the global feed precisely
        // because a feed ranks.
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $popular = $this->update($artist, 'Older but liked', '-3 days');
        $popular->forceFill(['likes' => 500])->save();
        $this->update($artist, 'Newer and quiet', '-1 hour');

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page
                ->where('updates.data.0.content', 'Newer and quiet')
                ->where('updates.data.1.content', 'Older but liked'));
    }

    #[Test]
    public function shows_are_ordered_soonest_first(): void
    {
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $later = Show::factory()->for($artist, 'hostArtist')->create(['starts_at' => now()->addDays(20)]);
        $sooner = Show::factory()->for($artist, 'hostArtist')->create(['starts_at' => now()->addDays(2)]);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page
                ->where('shows.0.id', $sooner->id)
                ->where('shows.1.id', $later->id));
    }

    // ---------------------------------------------------------------
    // Safety and bounds
    // ---------------------------------------------------------------

    #[Test]
    public function a_blocked_artist_is_absent_from_your_scene(): void
    {
        $user = $this->hartfordUser();
        $blocked = $this->artistIn('Hartford', 'Blocked Act');

        Show::factory()->for($blocked, 'hostArtist')->oneOff('Hartford')
            ->create(['starts_at' => now()->addDays(3)]);
        $user->blockedArtists()->attach($blocked);

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page->has('nearby.shows', 0));
    }

    #[Test]
    public function an_account_with_no_town_still_gets_its_follows(): void
    {
        // Half the page is about a place. Without one, say so rather than
        // rendering an empty heading.
        $user = User::factory()->create(['lat' => null, 'lng' => null]);
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $this->update($artist, 'Still reaches you');

        $this->actingAs($user)->get(route('scene.home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('updates.data', 1)
                ->has('nearby.shows', 0)
                ->where('area', null));
    }

    #[Test]
    public function the_updates_list_is_bounded_and_paginated(): void
    {
        // Personal, so uncached -- which makes bounding it the only
        // protection it has (the MapFilters::hiddenFor rule).
        $user = $this->hartfordUser();
        $artist = $this->artistIn('Hartford', 'Followed Act');
        $user->followedArtists()->attach($artist);

        $perPage = (int) config('riff.scene_home.updates_per_page');

        foreach (range(1, $perPage + 3) as $i) {
            $this->update($artist, "Update {$i}", "-{$i} hours");
        }

        $this->actingAs($user)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page
                ->has('updates.data', $perPage)
                ->where('updates.total', $perPage + 3)
                ->where('updates.nextPage', 2));

        $this->actingAs($user)->get(route('scene.home', ['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->has('updates.data', 3)
                ->where('updates.nextPage', null));
    }

    #[Test]
    public function its_query_count_does_not_grow_with_the_follow_list(): void
    {
        $user = $this->hartfordUser();

        $follow = function (int $count) use ($user) {
            foreach (range(1, $count) as $i) {
                $artist = $this->artistIn('Hartford', 'Act '.uniqid());
                $user->followedArtists()->attach($artist);
                $this->update($artist, 'Hello from '.$artist->name);
            }
        };

        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('scene.home'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // A discarded render before each measurement: creating artists
        // bumps the map cache, so a cold call measures the cache refill
        // rather than the page.
        $follow(2);
        $count();
        $small = $count();

        $follow(5);
        $count();
        $larger = $count();

        $this->assertSame($small, $larger, "Grew from {$small} to {$larger} queries.");
    }

    #[Test]
    public function a_guest_cannot_reach_your_scene(): void
    {
        // A guest's home is the map; / already redirects there.
        $this->get(route('scene.home'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------
    // The old feed
    // ---------------------------------------------------------------

    #[Test]
    public function the_global_feed_still_exists(): void
    {
        // Kept on purpose (owner decision): it is the starting point for a
        // future followed-entity digest, demoted rather than deleted.
        $user = $this->hartfordUser();

        $this->actingAs($user)->get(route('feed.index'))->assertOk();
    }
}
