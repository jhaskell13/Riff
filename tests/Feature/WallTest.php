<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\AuthorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The artist page as the fans' home: two voices, replies, and who is
 * allowed to speak.
 */
class WallTest extends TestCase
{
    use RefreshDatabase;

    private function member(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    /**
     * End the session. actingAs() persists for the rest of the test, so
     * without this a "what does a guest see" assertion is really made as
     * whoever last acted -- and AuthenticatedLayout prints the signed-in
     * user's name in the nav, which quietly defeats an assertDontSee.
     */
    private function logOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard()->logout();
        $this->app['session']->flush();
    }

    private function follower(Artist $artist): User
    {
        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        return $user;
    }

    // ---------------------------------------------------------------
    // The voice
    // ---------------------------------------------------------------

    #[Test]
    public function a_member_posts_as_the_artist(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs($member)
            ->post(route('wall.posts.store', $artist), [
                'content' => 'New single is up.',
                'as_artist' => true,
            ])
            ->assertSessionHasNoErrors();

        $post = Post::sole();

        $this->assertSame(AuthorContext::Artist, $post->author_context);
        $this->assertSame($artist->id, $post->artist_id);
        // Who typed it is still recorded -- for the audit trail and for
        // artist-level moderation. It is just never published.
        $this->assertSame($member->id, $post->user_id);
    }

    #[Test]
    public function an_artist_voice_post_never_names_the_member_who_typed_it(): void
    {
        // The band said it. Which member pressed post is internal, and a
        // public payload that leaked it would out the person behind an
        // identity that exists to be shared.
        $artist = Artist::factory()->create(['name' => 'The Silver Mill']);
        $member = $this->member($artist);
        $member->forceFill(['name' => 'Verya Unmistakable Membername'])->save();

        $this->actingAs($member)->post(route('wall.posts.store', $artist), [
            'content' => 'New single is up.',
            'as_artist' => true,
        ]);

        $this->logOut();

        $response = $this->get(route('artists.show', $artist));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('wall.posts.0.authorContext', 'artist')
            ->where('wall.posts.0.user', null)
            ->where('wall.posts.0.artist.name', 'The Silver Mill'));

        // Scoped to the wall, not the whole page: a member's name appears
        // in the public Members list by design. What must never happen is
        // the *post* carrying it.
        $this->assertStringNotContainsString(
            'Verya Unmistakable Membername',
            json_encode($response->viewData('page')['props']['wall']),
        );
    }

    #[Test]
    public function a_non_member_cannot_post_as_the_artist(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->follower($artist))
            ->post(route('wall.posts.store', $artist), [
                'content' => 'We are breaking up.',
                'as_artist' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, Post::count());
    }

    #[Test]
    public function the_voice_cannot_be_set_by_the_request(): void
    {
        // author_context is fillable but is never a validation rule, so
        // ...validated() can't carry it: the controller sets it from the
        // authorization result.
        $artist = Artist::factory()->create();

        $this->actingAs($this->follower($artist))
            ->post(route('wall.posts.store', $artist), [
                'content' => 'Hello',
                'author_context' => 'artist',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AuthorContext::Fan, Post::sole()->author_context);
    }

    #[Test]
    public function a_fan_post_names_the_fan(): void
    {
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->post(route('wall.posts.store', $artist), [
            'content' => 'Saw these three last night.',
        ]);

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.authorContext', 'fan')
                ->where('wall.posts.0.user.name', $fan->name));
    }

    #[Test]
    public function a_wall_shows_only_its_own_artists_posts(): void
    {
        $artist = Artist::factory()->create();
        $other = Artist::factory()->create();

        $this->actingAs($this->member($artist))->post(route('wall.posts.store', $artist), [
            'content' => 'Ours', 'as_artist' => true,
        ]);
        $this->actingAs($this->member($other))->post(route('wall.posts.store', $other), [
            'content' => 'Theirs', 'as_artist' => true,
        ]);

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts', 1)
                ->where('wall.posts.0.content', 'Ours'));
    }

    #[Test]
    public function a_guest_can_read_a_wall_but_not_post(): void
    {
        $artist = Artist::factory()->create();
        $this->actingAs($this->member($artist))->post(route('wall.posts.store', $artist), [
            'content' => 'Public words', 'as_artist' => true,
        ]);

        $this->logOut();

        $this->get(route('artists.show', $artist))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.content', 'Public words')
                ->where('canPostOnWall', false)
                ->where('canPostAsArtist', false));

        $this->post(route('wall.posts.store', $artist), ['content' => 'Let me in'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_wall_badge_says_artist_not_the_bands_name(): void
    {
        // The author line already says which band said it; repeating the
        // name in the badge beside it was noise.
        $artist = Artist::factory()->create(['name' => 'The Silver Mill']);
        $member = $this->member($artist);

        Post::factory()->asArtist($artist, $member)->create();

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.badge.label', 'Artist')
                ->where('wall.posts.0.badge.tone', 'owner'));
    }

    #[Test]
    public function a_band_member_speaking_as_themselves_is_badged_member(): void
    {
        // Otherwise someone in the band reads as any other fan on their
        // own page.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs($member)->post(route('wall.posts.store', $artist), [
            'content' => 'Speaking for myself here',
            'as_artist' => false,
        ])->assertSessionHasNoErrors();

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.authorContext', 'fan')
                // On a wall there is only one band it could be, so the
                // badge does not name it.
                ->where('wall.posts.0.badge.label', 'Member')
                ->where('wall.posts.0.badge.tone', 'member'));
    }

    #[Test]
    public function an_ordinary_fan_on_a_wall_gets_no_badge(): void
    {
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->post(route('wall.posts.store', $artist), ['content' => 'Love this']);

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->where('wall.posts.0.badge', null));
    }

    #[Test]
    public function an_artist_voice_reply_is_badged_artist_too(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = Post::factory()->asArtist($artist, $member)->create();

        $this->actingAs($member)->post(route('wall.comments.store', $post), [
            'content' => 'Thanks', 'as_artist' => true,
        ]);

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.comments.0.badge.label', 'Artist'));
    }

    // ---------------------------------------------------------------
    // Who may post: wall_mode
    // ---------------------------------------------------------------

    #[Test]
    public function by_default_only_followers_may_post(): void
    {
        $artist = Artist::factory()->create();

        $this->assertSame('fans', $artist->wall_mode);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello'])
            ->assertForbidden();

        $this->actingAs($this->follower($artist))
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function an_artist_can_open_the_wall_to_everyone(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.posts.store', $artist), ['content' => 'Just found you'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Post::count());
    }

    #[Test]
    public function an_artist_can_close_the_wall(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'members_only']);

        $this->actingAs($this->follower($artist))
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello'])
            ->assertForbidden();

        // A member is never shut out of their own page by their own
        // setting -- that would read as a bug.
        $this->actingAs($this->member($artist))
            ->post(route('wall.posts.store', $artist), ['content' => 'Update'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_listener_who_blocked_the_artist_cannot_post(): void
    {
        // Blocking says "I don't want to see this artist". Posting on
        // their wall contradicts that, whatever the wall mode.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $blocker = User::factory()->create();
        $blocker->blockedArtists()->attach($artist);

        $this->actingAs($blocker)
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello'])
            ->assertForbidden();
    }

    #[Test]
    public function the_wall_mode_is_validated_against_the_vocabulary(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs($member)
            ->patch(route('artists.update', $artist), [
                ...$this->artistPayload($artist),
                'wall_mode' => 'anarchy',
            ])
            ->assertSessionHasErrors('wall_mode');
    }

    #[Test]
    public function a_member_can_change_the_wall_mode(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.update', $artist), [
                ...$this->artistPayload($artist),
                'wall_mode' => 'everyone',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('everyone', $artist->fresh()->wall_mode);
    }

    // ---------------------------------------------------------------
    // Replies
    // ---------------------------------------------------------------

    #[Test]
    public function fans_reply_and_the_artist_answers_in_its_own_voice(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $post = Post::factory()->asArtist($artist, $member)->create();

        $this->actingAs($fan)->post(route('wall.comments.store', $post), [
            'content' => 'Any chance of a tape run?',
        ])->assertSessionHasNoErrors();

        $this->actingAs($member)->post(route('wall.comments.store', $post), [
            'content' => 'Fifty of them, Friday.',
            'as_artist' => true,
        ])->assertSessionHasNoErrors();

        $comments = $post->comments()->oldest()->get();

        $this->assertCount(2, $comments);
        $this->assertFalse($comments[0]->isArtistVoice());
        $this->assertNull($comments[0]->artist_id);
        $this->assertTrue($comments[1]->isArtistVoice());
        $this->assertSame($artist->id, $comments[1]->artist_id);
    }

    #[Test]
    public function an_artist_voice_reply_never_names_the_member(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $member->forceFill(['name' => 'Verya Unmistakable Membername'])->save();

        $post = Post::factory()->asArtist($artist, $member)->create();

        $this->actingAs($member)->post(route('wall.comments.store', $post), [
            'content' => 'Fifty of them, Friday.',
            'as_artist' => true,
        ]);

        $this->logOut();

        $response = $this->get(route('artists.show', $artist));

        $response->assertInertia(fn ($page) => $page
            ->where('wall.posts.0.comments.0.isArtist', true)
            ->where('wall.posts.0.comments.0.user', null));

        // As above: scoped to the wall, since the Members list names them.
        $this->assertStringNotContainsString(
            'Verya Unmistakable Membername',
            json_encode($response->viewData('page')['props']['wall']),
        );
    }

    #[Test]
    public function a_non_member_cannot_reply_as_the_artist(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();

        $this->actingAs($this->follower($artist))
            ->post(route('wall.comments.store', $post), [
                'content' => 'We quit',
                'as_artist' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, Comment::count());
    }

    #[Test]
    public function replies_are_one_level_deep(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $fan = $this->follower($artist);

        $top = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('wall.comments.store', $post), [
            'content' => 'A reply',
            'parent_id' => $top->id,
        ])->assertSessionHasNoErrors();

        $reply = Comment::where('parent_id', $top->id)->sole();

        // Replying to a reply is refused: a wall has to read on a phone.
        $this->actingAs($fan)->post(route('wall.comments.store', $post), [
            'content' => 'Too deep',
            'parent_id' => $reply->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame(2, Comment::count());
    }

    #[Test]
    public function a_reply_cannot_be_smuggled_onto_another_posts_thread(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $post = Post::factory()->asArtist($artist, $member)->create();
        $otherPost = Post::factory()->asArtist($artist, $member)->create();

        $elsewhere = Comment::factory()->create([
            'commentable_type' => $otherPost->getMorphClass(),
            'commentable_id' => $otherPost->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('wall.comments.store', $post), [
            'content' => 'Wrong thread',
            'parent_id' => $elsewhere->id,
        ])->assertSessionHasErrors('parent_id');
    }

    #[Test]
    public function commenting_follows_the_same_wall_rule_as_posting(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'fans']);
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();

        $this->actingAs(User::factory()->create())
            ->post(route('wall.comments.store', $post), ['content' => 'Hello'])
            ->assertForbidden();

        $this->actingAs($this->follower($artist))
            ->post(route('wall.comments.store', $post), ['content' => 'Hello'])
            ->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------
    // Reactions
    // ---------------------------------------------------------------

    #[Test]
    public function liking_a_post_is_idempotent(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->post(route('wall.posts.like', $post));
        $this->actingAs($fan)->post(route('wall.posts.like', $post));

        $this->assertSame(1, $post->fresh()->likes);
        $this->assertSame(1, $post->likers()->count());
    }

    #[Test]
    public function unliking_never_drives_the_counter_negative(): void
    {
        // The column is unsigned, and a negative write is a hard error on
        // MySQL in strict mode.
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->delete(route('wall.posts.unlike', $post));
        $this->actingAs($fan)->delete(route('wall.posts.unlike', $post));

        $this->assertSame(0, $post->fresh()->likes);
    }

    #[Test]
    public function a_like_round_trips(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->post(route('wall.posts.like', $post));
        $this->assertSame(1, $post->fresh()->likes);

        $this->actingAs($fan)->delete(route('wall.posts.unlike', $post));
        $this->assertSame(0, $post->fresh()->likes);
        $this->assertSame(0, $post->likers()->count());
    }

    #[Test]
    public function comments_can_be_liked(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $fan = $this->follower($artist);
        $comment = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('wall.comments.like', $comment));

        $this->assertSame(1, $comment->fresh()->likes);
    }

    #[Test]
    public function the_wall_is_ordered_by_time_and_never_by_likes(): void
    {
        // ROADMAP principle 2: like counts don't drive ranking. A new
        // band's post is as visible as a popular one's.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $popular = Post::factory()->asArtist($artist, $member)->create([
            'content' => 'Older but liked',
            'published_at' => now()->subDays(3),
            'likes' => 500,
        ]);
        Post::factory()->asArtist($artist, $member)->create([
            'content' => 'Newer and quiet',
            'published_at' => now()->subHour(),
            'likes' => 0,
        ]);

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.content', 'Newer and quiet')
                ->where('wall.posts.1.content', 'Older but liked'));

        $this->assertSame(500, $popular->fresh()->likes);
    }

    // ---------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------

    #[Test]
    public function a_fan_deletes_their_own_post_and_no_one_elses(): void
    {
        $artist = Artist::factory()->create();
        $mine = Post::factory()->onWall($artist)->create(['user_id' => $this->follower($artist)->id]);
        $theirs = Post::factory()->onWall($artist)->create(['user_id' => $this->follower($artist)->id]);

        $this->actingAs(User::find($mine->user_id))
            ->delete(route('wall.posts.destroy', $mine))
            ->assertSessionHasNoErrors();
        $this->assertNull(Post::find($mine->id));

        $this->actingAs(User::find($mine->user_id))
            ->delete(route('wall.posts.destroy', $theirs))
            ->assertForbidden();
        $this->assertNotNull(Post::find($theirs->id));
    }

    #[Test]
    public function any_member_can_delete_an_artist_voice_post(): void
    {
        // The band said it, not the member who typed it, so the band --
        // any of it -- can take it back.
        $artist = Artist::factory()->create();
        $author = $this->member($artist);
        $bandmate = $this->member($artist);

        $post = Post::factory()->asArtist($artist, $author)->create();

        $this->actingAs($bandmate)
            ->delete(route('wall.posts.destroy', $post))
            ->assertSessionHasNoErrors();

        $this->assertNull(Post::find($post->id));
    }

    #[Test]
    public function a_member_cannot_delete_a_fans_post_from_their_wall(): void
    {
        // Taking a fan's post off your wall is *hiding*, which is
        // artist-level moderation and stays recoverable -- not deletion.
        $artist = Artist::factory()->create();
        $post = Post::factory()->onWall($artist)->create(['user_id' => $this->follower($artist)->id]);

        $this->actingAs($this->member($artist))
            ->delete(route('wall.posts.destroy', $post))
            ->assertForbidden();

        $this->assertNotNull(Post::find($post->id));
    }

    // ---------------------------------------------------------------
    // Safety
    // ---------------------------------------------------------------

    #[Test]
    public function a_comment_can_be_reported(): void
    {
        // Every new surface is reportable through the one endpoint.
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $comment = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $this->follower($artist)->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('reports.store'), [
                'type' => 'comment',
                'id' => $comment->id,
                'reason' => 'hate',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reports', [
            'reportable_type' => $comment->getMorphClass(),
            'reportable_id' => $comment->id,
        ]);
    }

    #[Test]
    public function a_moderator_can_remove_a_comment_without_destroying_it(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();
        $comment = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $this->follower($artist)->id,
        ]);

        $reporter = User::factory()->create();
        $this->actingAs($reporter)->post(route('reports.store'), [
            'type' => 'comment', 'id' => $comment->id, 'reason' => 'hate',
        ]);

        $moderator = User::factory()->create();
        $moderator->forceFill(['is_moderator' => true])->save();

        $report = Report::sole();

        $this->actingAs($moderator)
            ->post(route('moderation.resolve', $report), ['action' => 'remove_comment'])
            ->assertSessionHasNoErrors();

        // The row stays for the audit trail; the thread filters on status.
        $this->assertSame(Comment::REMOVED, $comment->fresh()->status);
        $this->assertNotNull(Comment::find($comment->id));

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts.0.comments', 0));
    }

    #[Test]
    public function a_walls_query_count_does_not_grow_with_its_contents(): void
    {
        // Reaction state is one EXISTS per row inside the wall query, and
        // the policies' membership/follow/block checks are memoised per
        // request -- so a busy wall costs the same as a quiet one. Done
        // naively this was 42 queries against 18; if it regresses, the
        // number here grows with the rows and this fails.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $fill = function (int $posts) use ($artist, $member, $fan) {
            foreach (range(1, $posts) as $i) {
                $post = Post::factory()->asArtist($artist, $member)->create();

                foreach (range(1, 3) as $j) {
                    $comment = Comment::factory()->create([
                        'commentable_type' => $post->getMorphClass(),
                        'commentable_id' => $post->id,
                        'user_id' => $fan->id,
                    ]);
                    Comment::factory()->replyTo($comment)->create(['user_id' => $fan->id]);
                }
            }
        };

        $count = function () use ($artist, $fan): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($fan)->get(route('artists.show', $artist))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $fill(3);

        // One discarded render first: the page also warms caches (the
        // local fan count among them), so a cold first call measures
        // those rather than the wall.
        $count();
        $small = $count();

        $fill(5);
        $larger = $count();

        $this->assertSame(
            $small,
            $larger,
            "A wall of 8 posts with 24 comments and 24 replies ran {$larger} queries "
            ."against {$small} for a third of the content.",
        );
    }

    #[Test]
    public function a_removed_post_leaves_the_wall(): void
    {
        $artist = Artist::factory()->create();
        $post = Post::factory()->asArtist($artist, $this->member($artist))->create();

        $post->forceFill(['status' => 'removed'])->save();

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts', 0));
    }

    /**
     * The artist update form's required fields, so a wall_mode test does
     * not fail on unrelated validation.
     *
     * @return array<string, mixed>
     */
    private function artistPayload(Artist $artist): array
    {
        return [
            'name' => $artist->name,
            'artist_type' => $artist->artist_type,
            'genres' => $artist->genres()->pluck('genres.id')->all(),
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
            'show_on_map' => $artist->show_on_map,
        ];
    }
}
