<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\WallModerationEvent;
use App\Support\WallAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Artist-level moderation: a band keeping its own page tidy.
 *
 * The rules under test, in one line each: artists hide and never destroy;
 * every action is reversible; every action is written to a trail; and a
 * platform moderator's decision is not the artist's to undo.
 */
class WallModerationTest extends TestCase
{
    use RefreshDatabase;

    private function member(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    private function follower(Artist $artist): User
    {
        $user = User::factory()->create();
        $user->followedArtists()->attach($artist);

        return $user;
    }

    private function fanPostOn(Artist $artist, ?User $author = null): Post
    {
        return Post::factory()->onWall($artist)->create([
            'user_id' => ($author ?? $this->follower($artist))->id,
        ]);
    }

    private function commentOn(Post $post, User $author): Comment
    {
        return Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $author->id,
        ]);
    }

    private function logOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    // ---------------------------------------------------------------
    // Hiding, and putting back
    // ---------------------------------------------------------------

    #[Test]
    public function a_member_hides_a_fan_post_without_destroying_it(): void
    {
        $artist = Artist::factory()->create();
        $post = $this->fanPostOn($artist);

        $this->actingAs($this->member($artist))
            ->post(route('wall.posts.hide', $post), ['note' => 'Off topic'])
            ->assertSessionHasNoErrors();

        $fresh = $post->fresh();

        $this->assertSame(Post::HIDDEN, $fresh->status);
        // The row is still there. That is the whole point.
        $this->assertNotNull($fresh);
        $this->assertSame($post->content, $fresh->content);
    }

    #[Test]
    public function a_hidden_post_leaves_the_public_wall_but_members_still_see_it(): void
    {
        $artist = Artist::factory()->create();
        $post = $this->fanPostOn($artist);
        $member = $this->member($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post));

        // The member, who may undo it, still sees it -- flagged.
        $this->actingAs($member)->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts', 1)
                ->where('wall.posts.0.isHidden', true)
                ->where('wall.posts.0.canModerate', true));

        // Nobody else learns it ever existed.
        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts', 0))
            ->assertDontSee($post->content);
    }

    #[Test]
    public function a_hidden_comment_leaves_the_public_thread(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = Post::factory()->asArtist($artist, $member)->create();
        $comment = $this->commentOn($post, $this->follower($artist));

        $this->actingAs($member)->post(route('wall.comments.hide', $comment))
            ->assertSessionHasNoErrors();

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts.0.comments', 0)
                ->where('wall.posts.0.commentsCount', 0))
            ->assertDontSee($comment->content);
    }

    #[Test]
    public function hiding_is_reversible(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post));
        $this->assertSame(Post::HIDDEN, $post->fresh()->status);

        $this->actingAs($member)->delete(route('wall.posts.unhide', $post))
            ->assertSessionHasNoErrors();

        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts', 1));
    }

    #[Test]
    public function every_action_is_written_to_the_trail(): void
    {
        // An artist who hid the wrong thing has to be able to see what
        // they did -- and a hide and its undo are two rows, never one row
        // rewritten.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $comment = $this->commentOn(
            Post::factory()->asArtist($artist, $member)->create(),
            $this->follower($artist),
        );

        $this->actingAs($member)->post(route('wall.comments.hide', $comment), ['note' => 'Wrong thread']);
        $this->actingAs($member)->delete(route('wall.comments.unhide', $comment));

        $trail = WallModerationEvent::query()
            ->where('subject_type', $comment->getMorphClass())
            ->where('subject_id', $comment->id)
            ->oldest('id')
            ->get();

        $this->assertCount(2, $trail);
        $this->assertSame(WallAction::Hide, $trail[0]->action);
        $this->assertSame('Wrong thread', $trail[0]->note);
        $this->assertSame($member->id, $trail[0]->actor_id);
        $this->assertSame(WallAction::Unhide, $trail[1]->action);
        $this->assertSame($artist->id, $trail[1]->artist_id);
    }

    #[Test]
    public function the_trail_is_shown_to_members_beside_what_they_hid(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post), ['note' => 'Spam']);

        $this->actingAs($member)->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wall.posts.0.moderation.action', 'hide')
                ->where('wall.posts.0.moderation.by', $member->name)
                ->where('wall.posts.0.moderation.note', 'Spam'));
    }

    #[Test]
    public function members_see_the_whole_history_not_just_the_current_state(): void
    {
        // Hidden, restored, hidden again is a different story from hidden
        // once, and the member deciding what to do next needs to see it.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post), ['note' => 'First look']);
        $this->actingAs($member)->delete(route('wall.posts.unhide', $post));
        $this->actingAs($member)->post(route('wall.posts.hide', $post), ['note' => 'On reflection']);

        $this->actingAs($member)->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts.0.moderationTrail', 3)
                // Newest first, so the current state reads at the top.
                ->where('wall.posts.0.moderationTrail.0.action', 'hide')
                ->where('wall.posts.0.moderationTrail.0.note', 'On reflection')
                ->where('wall.posts.0.moderationTrail.1.action', 'unhide')
                ->where('wall.posts.0.moderationTrail.2.note', 'First look'));
    }

    #[Test]
    public function the_trail_is_never_sent_to_anyone_but_members(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post));
        $this->actingAs($member)->delete(route('wall.posts.unhide', $post));

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->has('wall.posts', 1)
                ->missing('wall.posts.0.moderationTrail')
                ->missing('wall.posts.0.moderation'));
    }

    #[Test]
    public function a_members_wall_does_not_grow_queries_with_its_moderation_history(): void
    {
        // The trail is an extra eager load for members; it must stay one
        // query, not one per row. Same guard as WallTest's.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $count = function () use ($artist, $member): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($member)->get(route('artists.show', $artist))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $hideSome = function (int $howMany) use ($artist, $member) {
            foreach (range(1, $howMany) as $i) {
                $post = $this->fanPostOn($artist);
                $this->actingAs($member)->post(route('wall.posts.hide', $post));
                $this->actingAs($member)->delete(route('wall.posts.unhide', $post));
            }
        };

        $hideSome(2);
        $count();
        $small = $count();

        $hideSome(4);
        $larger = $count();

        $this->assertSame($small, $larger, "Grew from {$small} to {$larger} queries.");
    }

    #[Test]
    public function hiding_is_idempotent_and_logs_once(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs($member)->post(route('wall.posts.hide', $post));
        $this->actingAs($member)->post(route('wall.posts.hide', $post));

        $this->assertSame(1, WallModerationEvent::count());
    }

    #[Test]
    public function an_artist_cannot_undo_a_platform_moderators_decision(): void
    {
        // The whole reason hide and remove are different words. A band
        // must not be able to restore something Riff took down.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $post->forceFill(['status' => Post::REMOVED])->save();

        $this->actingAs($member)->delete(route('wall.posts.unhide', $post))
            ->assertSessionHasNoErrors();

        $this->assertSame(Post::REMOVED, $post->fresh()->status);
        $this->assertSame(0, WallModerationEvent::count());
    }

    #[Test]
    public function a_removed_item_cannot_be_relabelled_as_the_artists_doing(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);
        $post->forceFill(['status' => Post::REMOVED])->save();

        $this->actingAs($member)->post(route('wall.posts.hide', $post));

        $this->assertSame(Post::REMOVED, $post->fresh()->status);
        $this->assertSame(0, WallModerationEvent::count());
    }

    #[Test]
    public function a_non_member_cannot_moderate_a_wall(): void
    {
        $artist = Artist::factory()->create();
        $post = $this->fanPostOn($artist);

        $this->actingAs($this->follower($artist))
            ->post(route('wall.posts.hide', $post))
            ->assertForbidden();

        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
    }

    #[Test]
    public function a_member_of_another_artist_cannot_moderate_this_wall(): void
    {
        $artist = Artist::factory()->create();
        $other = Artist::factory()->create();
        $post = $this->fanPostOn($artist);

        $this->actingAs($this->member($other))
            ->post(route('wall.posts.hide', $post))
            ->assertForbidden();
    }

    #[Test]
    public function there_is_no_artist_route_that_deletes_someone_elses_words(): void
    {
        // Hiding is the only artist-level power over a fan's content; the
        // delete route answers only to its author.
        $artist = Artist::factory()->create();
        $post = $this->fanPostOn($artist);

        $this->actingAs($this->member($artist))
            ->delete(route('wall.posts.destroy', $post))
            ->assertForbidden();

        $this->assertNotNull(Post::find($post->id));
    }

    // ---------------------------------------------------------------
    // Bans
    // ---------------------------------------------------------------

    #[Test]
    public function a_banned_listener_cannot_post_but_keeps_following_and_reading(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $this->actingAs($member)->post(route('wall.bans.store', $artist), [
            'user_id' => $fan->id,
            'reason' => 'Same promo under every update',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($fan->fresh()->isBannedFromWall($artist));

        // Cannot post.
        $this->actingAs($fan)
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello again'])
            ->assertForbidden();

        // Still a fan, and can still read the page.
        $this->assertTrue($fan->fresh()->follows($artist));
        $this->actingAs($fan)->get(route('artists.show', $artist))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canPostOnWall', false));
    }

    #[Test]
    public function a_ban_leaves_what_they_already_wrote_alone(): void
    {
        // "Stop posting here" and "take down what you said" are separate
        // decisions on purpose.
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);
        $post = $this->fanPostOn($artist, $fan);

        $this->actingAs($this->member($artist))
            ->post(route('wall.bans.store', $artist), ['user_id' => $fan->id]);

        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);

        $this->logOut();
        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->has('wall.posts', 1));
    }

    #[Test]
    public function a_ban_is_reversible_and_both_halves_are_logged(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $this->actingAs($member)->post(route('wall.bans.store', $artist), ['user_id' => $fan->id]);
        $this->actingAs($member)->delete(route('wall.bans.destroy', [$artist, $fan]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($fan->fresh()->isBannedFromWall($artist));
        $this->assertDatabaseMissing('artist_wall_bans', [
            'artist_id' => $artist->id,
            'user_id' => $fan->id,
        ]);

        $actions = WallModerationEvent::query()->oldest('id')->pluck('action')->all();
        $this->assertSame([WallAction::Ban, WallAction::Unban], $actions);

        // And they can post again.
        $this->actingAs($fan)
            ->post(route('wall.posts.store', $artist), ['content' => 'Thanks'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_member_cannot_be_banned_from_their_own_wall(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $bandmate = $this->member($artist);

        $this->actingAs($member)->post(route('wall.bans.store', $artist), [
            'user_id' => $bandmate->id,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($bandmate->fresh()->isBannedFromWall($artist));
        $this->assertSame(0, WallModerationEvent::count());
    }

    #[Test]
    public function a_non_member_cannot_ban_anyone(): void
    {
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);

        $this->actingAs($this->follower($artist))
            ->post(route('wall.bans.store', $artist), ['user_id' => $fan->id])
            ->assertForbidden();
    }

    #[Test]
    public function members_see_their_own_ban_list(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $this->actingAs($member)->post(route('wall.bans.store', $artist), [
            'user_id' => $fan->id,
            'reason' => 'Promo spam',
        ]);

        $this->actingAs($member)->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('wallBans.0.userId', $fan->id)
                ->where('wallBans.0.reason', 'Promo spam'));
    }

    #[Test]
    public function the_ban_list_is_never_shown_to_anyone_else(): void
    {
        // Who a band has barred is their business, not the public's.
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);
        $fan->forceFill(['name' => 'Verya Unmistakable Banname'])->save();

        $this->actingAs($this->member($artist))
            ->post(route('wall.bans.store', $artist), ['user_id' => $fan->id]);

        $this->logOut();
        $response = $this->get(route('artists.show', $artist));

        $response->assertInertia(fn ($page) => $page->where('wallBans', []));
        $response->assertDontSee('Verya Unmistakable Banname');
    }

    // ---------------------------------------------------------------
    // Escalating to the platform
    // ---------------------------------------------------------------

    #[Test]
    public function a_member_can_report_content_to_platform_moderators(): void
    {
        // The artist's own route to getting something taken *down*: hiding
        // is theirs, removal is a second person's decision.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $comment = $this->commentOn(
            Post::factory()->asArtist($artist, $member)->create(),
            $this->follower($artist),
        );

        $this->actingAs($member)->post(route('reports.store'), [
            'type' => 'comment',
            'id' => $comment->id,
            'reason' => 'hate',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reports', [
            'reportable_type' => $comment->getMorphClass(),
            'reportable_id' => $comment->id,
            'reporter_id' => $member->id,
        ]);
    }

    #[Test]
    public function hiding_something_does_not_close_a_report_about_it(): void
    {
        // Two separate systems: the artist tidying their page says nothing
        // about whether Riff should act.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $post = $this->fanPostOn($artist);

        $this->actingAs(User::factory()->create())->post(route('reports.store'), [
            'type' => 'post', 'id' => $post->id, 'reason' => 'spam',
        ]);

        $this->actingAs($member)->post(route('wall.posts.hide', $post));

        $this->assertSame(1, Report::query()->open()->count());
    }
}
