<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSupportLink;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_creation_is_rate_limited_per_user(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($user)
                ->post(route('feed.store'), ['content' => "Post {$i}"])
                ->assertRedirect(route('feed.index'));
        }

        $this->actingAs($user)
            ->post(route('feed.store'), ['content' => 'One too many'])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('posts', 10);
    }

    public function test_support_link_clicks_are_rate_limited(): void
    {
        // The click counter is public and unauthenticated, so it needs a
        // ceiling -- but a generous one, since throttling real readers
        // would corrupt the number rather than just delay it.
        $artist = Artist::factory()->create();
        $link = ArtistSupportLink::factory()->for($artist)->create();
        $route = route('artists.support.click', ['artist' => $artist->id, 'link' => $link->id]);
        $limit = (int) config('riff.support.click_rate_limit_per_minute');

        foreach (range(1, $limit) as $ignored) {
            $this->post($route)->assertNoContent();
        }

        $this->post($route)->assertTooManyRequests();

        $this->assertSame($limit, $link->fresh()->clicks);
    }

    public function test_wall_posts_are_rate_limited(): void
    {
        // A fan post is the cheapest thing to flood someone's page with.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $user = User::factory()->create();
        $limit = (int) config('riff.wall.post_rate_limit_per_minute');

        foreach (range(1, $limit) as $i) {
            $this->actingAs($user)
                ->post(route('wall.posts.store', $artist), ['content' => "Post {$i}"])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($user)
            ->post(route('wall.posts.store', $artist), ['content' => 'One too many'])
            ->assertTooManyRequests();

        $this->assertSame($limit, Post::count());
    }

    public function test_the_limit_is_per_user_not_global(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($first)->post(route('feed.store'), ['content' => "Post {$i}"]);
        }

        $this->actingAs($second)
            ->post(route('feed.store'), ['content' => 'Different user'])
            ->assertRedirect(route('feed.index'));
    }
}
