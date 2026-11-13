<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\LineupEntry;
use App\Models\Report;
use App\Models\Show;
use App\Models\User;
use App\Models\WallModerationEvent;
use App\Support\WallAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A show's thread: "who's going?", "what time are you on?".
 *
 * The same comments, replies, likes and moderation as an artist wall, on a
 * different subject -- which is what the polymorphic comments were for. What
 * differs is that the thread *closes* after the night, and that it belongs
 * to the host artist.
 */
class ShowThreadTest extends TestCase
{
    use RefreshDatabase;

    private function host(): Artist
    {
        return Artist::factory()->create();
    }

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

    private function upcomingShow(Artist $host): Show
    {
        return Show::factory()->for($host, 'hostArtist')->create([
            'starts_at' => now()->addDays(3),
        ]);
    }

    private function logOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
    }

    // ---------------------------------------------------------------
    // Posting
    // ---------------------------------------------------------------

    #[Test]
    public function a_fan_posts_in_a_show_thread_and_the_host_answers_as_the_artist(): void
    {
        $host = $this->host();
        $member = $this->member($host);
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $this->actingAs($fan)->post(route('shows.comments.store', $show), [
            'content' => 'What time are you on?',
        ])->assertSessionHasNoErrors();

        $this->actingAs($member)->post(route('shows.comments.store', $show), [
            'content' => 'Second, about nine.',
            'as_artist' => true,
        ])->assertSessionHasNoErrors();

        $comments = $show->comments()->oldest()->get();

        $this->assertCount(2, $comments);
        $this->assertFalse($comments[0]->isArtistVoice());
        $this->assertTrue($comments[1]->isArtistVoice());
        // The voice names the *host* artist, the one that owns the night.
        $this->assertSame($host->id, $comments[1]->artist_id);
    }

    #[Test]
    public function an_artist_voice_answer_never_names_the_member(): void
    {
        $host = $this->host();
        $member = $this->member($host);
        $member->forceFill(['name' => 'Verya Unmistakable Membername'])->save();
        $show = $this->upcomingShow($host);

        $this->actingAs($member)->post(route('shows.comments.store', $show), [
            'content' => 'Second, about nine.',
            'as_artist' => true,
        ]);

        $this->logOut();
        $response = $this->get(route('shows.show', $show));

        $response->assertInertia(fn ($page) => $page
            ->where('thread.comments.0.isArtist', true)
            ->where('thread.comments.0.user', null));
        $this->assertStringNotContainsString(
            'Verya Unmistakable Membername',
            json_encode($response->viewData('page')['props']['thread']),
        );
    }

    #[Test]
    public function a_non_member_cannot_answer_as_the_host(): void
    {
        $host = $this->host();
        $show = $this->upcomingShow($host);

        $this->actingAs($this->follower($host))
            ->post(route('shows.comments.store', $show), [
                'content' => 'We are not playing',
                'as_artist' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, Comment::count());
    }

    #[Test]
    public function a_guest_reads_the_thread_but_cannot_post(): void
    {
        $host = $this->host();
        $show = $this->upcomingShow($host);
        Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $this->follower($host)->id,
            'content' => 'Public question',
        ]);

        $this->logOut();

        $this->get(route('shows.show', $show))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.content', 'Public question')
                ->where('thread.canComment', false));

        $this->post(route('shows.comments.store', $show), ['content' => 'Let me in'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function any_verified_user_can_post_in_a_show_thread(): void
    {
        // A show is a public event, not the host's own page. Requiring a
        // follow first would be a strange tax on "is this all ages?" --
        // which is exactly the question a thread exists to answer.
        $host = $this->host();
        $show = $this->upcomingShow($host);

        $this->actingAs(User::factory()->create())
            ->post(route('shows.comments.store', $show), ['content' => 'Is this all ages?'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Comment::count());
    }

    #[Test]
    public function a_closed_wall_does_not_close_the_hosts_show_threads(): void
    {
        // The two are governed separately on purpose: a band may want a
        // quiet page and still answer questions about the night.
        $host = Artist::factory()->create(['wall_mode' => 'members_only']);
        $show = $this->upcomingShow($host);

        $this->actingAs(User::factory()->create())
            ->post(route('shows.comments.store', $show), ['content' => 'What time?'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function someone_who_blocked_the_host_cannot_post_in_its_thread(): void
    {
        // Blocking says "I don't want to see this artist"; posting under
        // their show contradicts it.
        $host = $this->host();
        $show = $this->upcomingShow($host);
        $blocker = User::factory()->create();
        $blocker->blockedArtists()->attach($host);

        $this->actingAs($blocker)
            ->post(route('shows.comments.store', $show), ['content' => 'Hello'])
            ->assertForbidden();
    }

    #[Test]
    public function a_wall_ban_also_bars_someone_from_the_hosts_show_threads(): void
    {
        // Both are the same artist's space; a ban that covered only half of
        // it would be a gap someone would find.
        $host = $this->host();
        $member = $this->member($host);
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $this->actingAs($member)->post(route('wall.bans.store', $host), ['user_id' => $fan->id]);

        $this->actingAs($fan)
            ->post(route('shows.comments.store', $show), ['content' => 'Hello again'])
            ->assertForbidden();
    }

    #[Test]
    public function replies_in_a_show_thread_are_one_level_deep(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $top = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('shows.comments.store', $show), [
            'content' => 'Me too',
            'parent_id' => $top->id,
        ])->assertSessionHasNoErrors();

        $reply = Comment::where('parent_id', $top->id)->sole();

        $this->actingAs($fan)->post(route('shows.comments.store', $show), [
            'content' => 'Too deep',
            'parent_id' => $reply->id,
        ])->assertSessionHasErrors('parent_id');
    }

    #[Test]
    public function a_reply_cannot_be_smuggled_from_another_shows_thread(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);
        $other = $this->upcomingShow($host);

        $elsewhere = Comment::factory()->create([
            'commentable_type' => $other->getMorphClass(),
            'commentable_id' => $other->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('shows.comments.store', $show), [
            'content' => 'Wrong thread',
            'parent_id' => $elsewhere->id,
        ])->assertSessionHasErrors('parent_id');
    }

    #[Test]
    public function a_comment_in_a_show_thread_can_be_liked(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $comment = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $fan->id,
        ]);

        $this->actingAs($fan)->post(route('wall.comments.like', $comment));

        $this->assertSame(1, $comment->fresh()->likes);
    }

    // ---------------------------------------------------------------
    // Who is who
    // ---------------------------------------------------------------

    #[Test]
    public function the_host_is_badged_host_and_another_act_on_the_bill_is_badged_differently(): void
    {
        $host = $this->host();
        $hostMember = $this->member($host);
        $support = Artist::factory()->create();
        $supportMember = $this->member($support);

        $show = $this->upcomingShow($host);
        // 'confirmed' is the column default; status is not fillable (see
        // the pending case below), so passing it here would be a no-op.
        $show->lineup()->create(['artist_id' => $support->id, 'position' => 2]);

        $this->actingAs($hostMember)->post(route('shows.comments.store', $show), [
            'content' => 'Doors at seven.', 'as_artist' => true,
        ])->assertSessionHasNoErrors();

        // An act on the bill can answer for its own set, not just the host.
        $this->actingAs($supportMember)->post(route('shows.comments.store', $show), [
            'content' => 'We open, about eight.', 'as_artist' => true,
        ])->assertSessionHasNoErrors();

        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.badge.label', 'Host')
                ->where('thread.comments.0.badge.tone', 'owner')
                ->where('thread.comments.1.badge.label', 'On the bill')
                ->where('thread.comments.1.badge.tone', 'lineup'));
    }

    #[Test]
    public function a_band_member_posting_as_themselves_is_badged_with_their_band(): void
    {
        // On a bill with several acts, "who is this person?" is exactly the
        // question a reader has, and a bare name does not answer it.
        $host = $this->host();
        $support = Artist::factory()->create(['name' => 'Gutter Vows']);
        $supportMember = $this->member($support);

        $show = $this->upcomingShow($host);
        // 'confirmed' is the column default; status is not fillable (see
        // the pending case below), so passing it here would be a no-op.
        $show->lineup()->create(['artist_id' => $support->id, 'position' => 2]);

        $this->actingAs($supportMember)->post(route('shows.comments.store', $show), [
            'content' => 'Bringing extra cables if anyone needs them.',
        ]);

        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.isArtist', false)
                ->where('thread.comments.0.badge.label', 'Member of Gutter Vows')
                ->where('thread.comments.0.badge.tone', 'member'));
    }

    #[Test]
    public function an_ordinary_listener_gets_no_badge(): void
    {
        $host = $this->host();
        $show = $this->upcomingShow($host);

        $this->actingAs(User::factory()->create())
            ->post(route('shows.comments.store', $show), ['content' => 'See you there']);

        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page->where('thread.comments.0.badge', null));
    }

    #[Test]
    public function a_pending_act_on_the_bill_has_no_standing_in_the_thread(): void
    {
        // Pending and declined acts appear on no public surface until they
        // have agreed to be on one -- a badge is a public surface.
        $host = $this->host();
        $invited = Artist::factory()->create();
        $invitedMember = $this->member($invited);

        $show = $this->upcomingShow($host);
        // status is not fillable -- only Lineup::sync() and the
        // confirm/decline endpoints write it, and the column defaults to
        // 'confirmed' -- so the fixture has to force it. (Passing it to
        // create() silently produced a *confirmed* entry and quietly
        // inverted this test.)
        $show->lineup()->create(['artist_id' => $invited->id, 'position' => 2])
            ->forceFill(['status' => LineupEntry::PENDING])
            ->save();

        $this->actingAs($invitedMember)
            ->post(route('shows.comments.store', $show), [
                'content' => 'We are playing this', 'as_artist' => true,
            ])
            ->assertForbidden();

        $this->actingAs($invitedMember)
            ->post(route('shows.comments.store', $show), ['content' => 'Hi'])
            ->assertSessionHasNoErrors();

        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page->where('thread.comments.0.badge', null));
    }

    // ---------------------------------------------------------------
    // Closing
    // ---------------------------------------------------------------

    #[Test]
    public function the_thread_closes_after_the_night_but_stays_readable(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $hours = (int) config('riff.shows.thread_closes_after_hours');

        $show = Show::factory()->for($host, 'hostArtist')->create([
            'starts_at' => now()->subHours($hours + 1),
        ]);

        Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $fan->id,
            'content' => 'That was great',
        ]);

        $this->actingAs($fan)
            ->post(route('shows.comments.store', $show), ['content' => 'Too late'])
            ->assertForbidden();

        // Read-only, not gone: what was said still explains the night.
        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.content', 'That was great')
                ->where('thread.isOpen', false)
                ->where('thread.canComment', false)
                ->whereNot('thread.closedReason', null));
    }

    #[Test]
    public function the_thread_is_still_open_just_after_the_doors(): void
    {
        // Tonight's thread must not shut the moment the show starts --
        // "where are you sitting?" is asked during it.
        $host = $this->host();
        $show = Show::factory()->for($host, 'hostArtist')->create([
            'starts_at' => now()->subHour(),
        ]);

        $this->actingAs($this->follower($host))
            ->post(route('shows.comments.store', $show), ['content' => 'Running late, save me a spot'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_cancelled_shows_thread_closes_but_the_page_stays(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $fan->id,
            'content' => 'Looking forward to it',
        ]);

        $this->actingAs($this->member($host))->post(route('shows.cancel', $show));

        $this->actingAs($fan)
            ->post(route('shows.comments.store', $show), ['content' => 'What happened?'])
            ->assertForbidden();

        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('thread.isOpen', false)
                ->where('thread.comments.0.content', 'Looking forward to it'));
    }

    // ---------------------------------------------------------------
    // Moderation: the host artist's members
    // ---------------------------------------------------------------

    #[Test]
    public function the_hosts_members_moderate_the_thread(): void
    {
        $host = $this->host();
        $member = $this->member($host);
        $show = $this->upcomingShow($host);

        $comment = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $this->follower($host)->id,
            'content' => 'Off topic promo',
        ]);

        $this->actingAs($member)
            ->post(route('wall.comments.hide', $comment), ['note' => 'Promo'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Comment::HIDDEN, $comment->fresh()->status);

        // The trail is kept against the host artist.
        $event = WallModerationEvent::sole();
        $this->assertSame(WallAction::Hide, $event->action);
        $this->assertSame($host->id, $event->artist_id);

        // Gone from the public thread, still there for the member.
        $this->logOut();
        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page->has('thread.comments', 0))
            ->assertDontSee('Off topic promo');

        $this->actingAs($member)->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->has('thread.comments', 1)
                ->where('thread.comments.0.isHidden', true));
    }

    #[Test]
    public function hiding_in_a_show_thread_is_reversible(): void
    {
        $host = $this->host();
        $member = $this->member($host);
        $show = $this->upcomingShow($host);
        $comment = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $this->follower($host)->id,
        ]);

        $this->actingAs($member)->post(route('wall.comments.hide', $comment));
        $this->actingAs($member)->delete(route('wall.comments.unhide', $comment));

        $this->assertSame(Comment::PUBLISHED, $comment->fresh()->status);
        $this->assertSame(2, WallModerationEvent::count());
    }

    #[Test]
    public function another_artists_members_cannot_moderate_this_thread(): void
    {
        $host = $this->host();
        $other = $this->host();
        $show = $this->upcomingShow($host);
        $comment = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $this->follower($host)->id,
        ]);

        $this->actingAs($this->member($other))
            ->post(route('wall.comments.hide', $comment))
            ->assertForbidden();

        $this->assertSame(Comment::PUBLISHED, $comment->fresh()->status);
    }

    #[Test]
    public function a_show_thread_comment_is_reportable_and_removable_by_a_moderator(): void
    {
        $host = $this->host();
        $show = $this->upcomingShow($host);
        $comment = Comment::factory()->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->id,
            'user_id' => $this->follower($host)->id,
        ]);

        $this->actingAs(User::factory()->create())->post(route('reports.store'), [
            'type' => 'comment',
            'id' => $comment->id,
            'reason' => 'hate',
        ])->assertSessionHasNoErrors();

        $moderator = User::factory()->create();
        $moderator->forceFill(['is_moderator' => true])->save();

        // The queue links a thread comment to the show it is in, so a
        // moderator can read it in context.
        $this->actingAs($moderator)->get(route('moderation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reports.data.0.subject.kind', 'comment')
                ->where('reports.data.0.subject.url', route('shows.show', $show)));

        $this->actingAs($moderator)->post(
            route('moderation.resolve', Report::sole()),
            ['action' => 'remove_comment'],
        )->assertSessionHasNoErrors();

        $this->assertSame(Comment::REMOVED, $comment->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Privacy
    // ---------------------------------------------------------------

    #[Test]
    public function the_thread_is_not_a_back_door_to_private_attendees(): void
    {
        // Showing your name on the attendee list is opt-in. A thread sits
        // next to that list, and must not become a way around it.
        $host = $this->host();
        $show = $this->upcomingShow($host);

        $private = $this->follower($host);
        $private->forceFill(['name' => 'Verya Unmistakable Privatename'])->save();

        $this->actingAs($private)->post(route('shows.rsvp', $show), [
            'status' => 'going',
            'is_public' => false,
        ])->assertSessionHasNoErrors();

        $this->logOut();
        $response = $this->get(route('shows.show', $show));

        $response->assertOk();
        $response->assertDontSee('Verya Unmistakable Privatename');
        $response->assertInertia(fn ($page) => $page
            ->where('rsvp.going', 1)
            ->where('rsvp.publicGoing', []));
    }

    #[Test]
    public function posting_in_the_thread_does_not_make_someone_an_attendee(): void
    {
        // Saying something is not saying you are going. Conflating them
        // would publish an attendance nobody declared.
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $this->actingAs($fan)->post(route('shows.comments.store', $show), [
            'content' => 'Might make it',
        ]);

        $this->assertDatabaseMissing('show_rsvps', [
            'show_id' => $show->id,
            'user_id' => $fan->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Shape
    // ---------------------------------------------------------------

    #[Test]
    public function a_long_thread_is_paginated_rather_than_shipped_whole(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);
        $perPage = (int) config('riff.wall.comments_per_page');

        foreach (range(1, $perPage + 4) as $i) {
            Comment::factory()->create([
                'commentable_type' => $show->getMorphClass(),
                'commentable_id' => $show->id,
                'user_id' => $fan->id,
                'content' => "Comment {$i}",
            ]);
        }

        $this->logOut();

        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->has('thread.comments', $perPage)
                ->where('thread.total', $perPage + 4)
                ->where('thread.nextPage', 2));

        $this->getJson(route('shows.comments.index', ['show' => $show->id, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(4, 'comments');
    }

    #[Test]
    public function a_show_threads_query_count_does_not_grow_with_its_contents(): void
    {
        $host = $this->host();
        $fan = $this->follower($host);
        $show = $this->upcomingShow($host);

        $fill = function (int $count) use ($show, $fan) {
            foreach (range(1, $count) as $i) {
                $parent = Comment::factory()->create([
                    'commentable_type' => $show->getMorphClass(),
                    'commentable_id' => $show->id,
                    'user_id' => $fan->id,
                ]);
                Comment::factory()->replyTo($parent)->create(['user_id' => $fan->id]);
            }
        };

        $count = function () use ($show, $fan): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($fan)->get(route('shows.show', $show))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $fill(2);
        $count();
        $small = $count();

        $fill(5);
        $larger = $count();

        $this->assertSame($small, $larger, "Grew from {$small} to {$larger} queries.");
    }
}
