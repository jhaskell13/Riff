<?php

namespace Tests\Unit\Models;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_liked_by_reflects_an_actual_like(): void
    {
        $post = Post::factory()->create();
        $liker = User::factory()->create();
        $other = User::factory()->create();

        $post->likers()->attach($liker);

        $this->assertTrue($post->isLikedBy($liker));
        $this->assertFalse($post->isLikedBy($other));
    }

    public function test_published_scope_excludes_drafts_and_future_posts(): void
    {
        $published = Post::factory()->create();
        Post::factory()->draft()->create();
        Post::factory()->create(['published_at' => now()->addDay()]);

        $this->assertSame([$published->id], Post::published()->pluck('id')->all());
    }
}
