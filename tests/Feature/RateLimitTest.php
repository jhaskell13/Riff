<?php

namespace Tests\Feature;

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
