<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A wall ships each post with only its first few replies, and each reply
 * with its first few answers. These are the endpoints that fetch the rest,
 * so a busy thread never turns the page into an unbounded scroll.
 */
class WallPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function member(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    private function logOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    /** @return array{0: Artist, 1: Post, 2: User} */
    private function wallWithComments(int $count): array
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = Post::factory()->asArtist($artist, $member)->create();
        $author = User::factory()->create();

        foreach (range(1, $count) as $i) {
            Comment::factory()->create([
                'commentable_type' => $post->getMorphClass(),
                'commentable_id' => $post->id,
                'user_id' => $author->id,
                'content' => "Comment {$i}",
            ]);
        }

        return [$artist, $post, $author];
    }

    #[Test]
    public function the_wall_ships_only_the_first_few_comments_but_counts_them_all(): void
    {
        $perPost = (int) config('riff.wall.comments_per_post');
        [$artist] = $this->wallWithComments($perPost + 5);

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts.0.comments', $perPost)
                // The count is the whole thread, which is what the client
                // subtracts from to offer "show N more".
                ->where('wall.posts.0.commentsCount', $perPost + 5));
    }

    #[Test]
    public function a_guest_can_fetch_more_comments(): void
    {
        // Public, because a guest reading a shared artist link has to be
        // able to read the whole conversation.
        $perPost = (int) config('riff.wall.comments_per_post');
        [, $post] = $this->wallWithComments($perPost + 4);

        $this->logOut();
        // From page 1: this endpoint pages by comments_per_page, not by the
        // handful the wall shipped, so the client fetches from the start
        // and filters by id rather than guessing an offset.
        $response = $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 1]));

        $response->assertOk();
        $response->assertJsonStructure(['comments', 'total', 'nextPage']);
        $this->assertSame($perPost + 4, $response->json('total'));
        $this->assertNotEmpty($response->json('comments'));
    }

    #[Test]
    public function fetching_comments_pages_and_reports_when_it_runs_out(): void
    {
        $perPage = (int) config('riff.wall.comments_per_page');
        [, $post] = $this->wallWithComments($perPage + 3);

        $this->logOut();

        $first = $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 1]));
        $this->assertCount($perPage, $first->json('comments'));
        $this->assertSame(2, $first->json('nextPage'));

        $second = $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 2]));
        $this->assertCount(3, $second->json('comments'));
        // Null, not 0: there is no next page to ask for.
        $this->assertNull($second->json('nextPage'));
    }

    #[Test]
    public function more_comments_never_repeats_one_already_sent(): void
    {
        $perPage = (int) config('riff.wall.comments_per_page');
        [, $post] = $this->wallWithComments($perPage * 2);

        $this->logOut();

        $firstIds = collect($this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 1]))
            ->json('comments'))->pluck('id');
        $secondIds = collect($this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 2]))
            ->json('comments'))->pluck('id');

        $this->assertEmpty($firstIds->intersect($secondIds));
    }

    #[Test]
    public function a_guest_can_fetch_more_replies(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = Post::factory()->asArtist($artist, $member)->create();
        $author = User::factory()->create();

        $parent = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $author->id,
        ]);

        $perComment = (int) config('riff.wall.replies_per_comment');

        foreach (range(1, $perComment + 4) as $i) {
            Comment::factory()->replyTo($parent)->create([
                'user_id' => $author->id,
                'content' => "Reply {$i}",
            ]);
        }

        $this->logOut();

        // The wall carries only the first few, and the count is the lot.
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts.0.comments.0.replies', $perComment)
                ->where('wall.posts.0.comments.0.repliesCount', $perComment + 4));

        $response = $this->getJson(route('wall.replies.index', ['comment' => $parent->id, 'page' => 1]));

        $response->assertOk();
        $this->assertSame($perComment + 4, $response->json('total'));
    }

    #[Test]
    public function fetched_comments_never_include_hidden_ones_for_the_public(): void
    {
        // The pagination endpoints answer to the same visibility rule as
        // the wall itself -- otherwise they would be a way around it.
        $perPost = (int) config('riff.wall.comments_per_post');
        [$artist, $post] = $this->wallWithComments($perPost + 4);
        $member = $artist->members()->first();

        $hidden = $post->comments()->latest('id')->first();
        $this->actingAs($member)->post(route('wall.comments.hide', $hidden));

        $this->logOut();
        $response = $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 1]));

        $this->assertSame($perPost + 3, $response->json('total'));
        $this->assertNotContains(
            $hidden->id,
            collect($response->json('comments'))->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_member_fetching_comments_still_sees_what_they_hid(): void
    {
        $perPost = (int) config('riff.wall.comments_per_post');
        [$artist, $post] = $this->wallWithComments($perPost + 4);
        $member = $artist->members()->first();

        $hidden = $post->comments()->latest('id')->first();
        $this->actingAs($member)->post(route('wall.comments.hide', $hidden));

        $response = $this->actingAs($member)
            ->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 1]));

        $this->assertSame($perPost + 4, $response->json('total'));
    }

    #[Test]
    public function an_absurd_page_number_is_bounded(): void
    {
        [, $post] = $this->wallWithComments(2);

        $this->logOut();

        $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => 999999999]))
            ->assertOk()
            ->assertJsonPath('comments', []);

        $this->getJson(route('wall.comments.index', ['post' => $post->id, 'page' => -5]))
            ->assertOk();
    }

    #[Test]
    public function replies_on_a_comment_that_is_not_on_an_artist_wall_are_not_served(): void
    {
        // A wall-less post is a leftover from the old global feed; there is
        // no artist page to authorize against.
        $post = Post::factory()->create(['artist_id' => null]);
        $comment = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->logOut();
        $this->getJson(route('wall.replies.index', $comment))->assertNotFound();
    }
}
