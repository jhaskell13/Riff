<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\LineupEntry;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use App\Support\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * In-app notifications: replies, follows, and the handful of other things
 * worth being told about.
 *
 * The rules under test are the three the Notifier exists to hold: does the
 * recipient want it, have they hidden the artist it is about, and is it
 * already in their bell.
 */
class NotificationTest extends TestCase
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

    /** @return array<int, array<string, mixed>> */
    private function bell(User $user): array
    {
        return $user->notifications()->get()
            ->map(fn ($n) => ['type' => $n->data['type'], 'title' => $n->data['title'], 'count' => $n->data['count']])
            ->all();
    }

    private function typesIn(User $user): array
    {
        return $user->notifications()->get()->pluck('data.type')->all();
    }

    // ---------------------------------------------------------------
    // Replies
    // ---------------------------------------------------------------

    #[Test]
    public function replying_to_a_fans_post_tells_the_fan(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $author = $this->follower($artist);
        $replier = User::factory()->create(['name' => 'Ana Reply']);

        $post = Post::factory()->onWall($artist)->create(['user_id' => $author->id]);

        $this->actingAs($replier)->post(route('wall.comments.store', $post), [
            'content' => 'Completely agree',
        ])->assertSessionHasNoErrors();

        $bell = $this->bell($author);

        $this->assertCount(1, $bell);
        $this->assertSame(NotificationType::Reply->value, $bell[0]['type']);
        $this->assertSame('Ana Reply replied to your post', $bell[0]['title']);
    }

    #[Test]
    public function replying_to_an_artist_voice_post_tells_every_member(): void
    {
        // The band said it, so answering it answers the band -- not
        // whichever member happened to type it.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $typist = $this->member($artist);
        $bandmate = $this->member($artist);

        $post = Post::factory()->asArtist($artist, $typist)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('wall.comments.store', $post), ['content' => 'Nice one']);

        $this->assertContains(NotificationType::Reply->value, $this->typesIn($typist));
        $this->assertContains(NotificationType::Reply->value, $this->typesIn($bandmate));
    }

    #[Test]
    public function replying_to_a_comment_tells_its_author_not_the_post_author(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $postAuthor = $this->follower($artist);
        $commenter = $this->follower($artist);

        $post = Post::factory()->onWall($artist)->create(['user_id' => $postAuthor->id]);
        $comment = Comment::factory()->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->id,
            'user_id' => $commenter->id,
        ]);

        $this->actingAs(User::factory()->create())->post(route('wall.comments.store', $post), [
            'content' => 'Replying to you',
            'parent_id' => $comment->id,
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->bell($commenter));
        $this->assertCount(0, $this->bell($postAuthor));
    }

    #[Test]
    public function nobody_is_told_about_their_own_reply(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $author = $this->follower($artist);
        $post = Post::factory()->onWall($artist)->create(['user_id' => $author->id]);

        $this->actingAs($author)->post(route('wall.comments.store', $post), [
            'content' => 'Replying to myself',
        ]);

        $this->assertCount(0, $this->bell($author));
    }

    #[Test]
    public function a_show_thread_question_reaches_the_hosts_members(): void
    {
        // A show has no author, so "who was answered" is the people
        // expected to field questions about the night.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $show = Show::factory()->for($artist, 'hostArtist')->create(['starts_at' => now()->addDays(3)]);

        $this->actingAs(User::factory()->create())->post(route('shows.comments.store', $show), [
            'content' => 'What time are you on?',
        ])->assertSessionHasNoErrors();

        $this->assertContains(NotificationType::Reply->value, $this->typesIn($member));
    }

    // ---------------------------------------------------------------
    // Follows
    // ---------------------------------------------------------------

    #[Test]
    public function following_an_artist_tells_its_members(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Silver Mill']);
        $member = $this->member($artist);
        $fan = User::factory()->create(['name' => 'Jo Fan']);

        $this->actingAs($fan)->post(route('artists.follow', $artist))->assertSessionHasNoErrors();

        $bell = $this->bell($member);

        $this->assertCount(1, $bell);
        $this->assertSame('Jo Fan is now a fan of The Silver Mill', $bell[0]['title']);
    }

    #[Test]
    public function following_twice_does_not_announce_twice(): void
    {
        // Following is idempotent; pressing the button again is not news.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = User::factory()->create();

        $this->actingAs($fan)->post(route('artists.follow', $artist));
        $this->actingAs($fan)->post(route('artists.follow', $artist));

        $this->assertCount(1, $this->bell($member));
    }

    #[Test]
    public function new_fans_fold_into_one_line(): void
    {
        // A band that picks up fans after a good night wants to know that,
        // not to scroll past it ten times.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        foreach (range(1, 4) as $i) {
            $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));
        }

        $bell = $this->bell($member);

        $this->assertCount(1, $bell);
        $this->assertSame(4, $bell[0]['count']);
    }

    // ---------------------------------------------------------------
    // Wall and updates
    // ---------------------------------------------------------------

    #[Test]
    public function a_fan_posting_on_a_wall_tells_the_band(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $member = $this->member($artist);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.posts.store', $artist), ['content' => 'Big fan']);

        $this->assertContains(NotificationType::WallPost->value, $this->typesIn($member));
    }

    #[Test]
    public function an_artist_posting_tells_its_followers_and_folds(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        foreach (range(1, 3) as $i) {
            $this->actingAs($member)->post(route('wall.posts.store', $artist), [
                'content' => "Update {$i}", 'as_artist' => true,
            ]);
        }

        $bell = $this->bell($fan);

        $this->assertCount(1, $bell);
        $this->assertSame(NotificationType::ArtistPost->value, $bell[0]['type']);
        $this->assertSame(3, $bell[0]['count']);
        // And the band is not told about its own posts.
        $this->assertCount(0, $this->bell($member));
    }

    // ---------------------------------------------------------------
    // Shows
    // ---------------------------------------------------------------

    #[Test]
    public function listing_a_show_tells_the_hosts_followers(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $fan = $this->follower($artist);

        $this->actingAs($member)->post(route('shows.store'), [
            'host_artist_id' => $artist->id,
            'title' => 'A night out',
            'venue_id' => Venue::factory()->create()->id,
            'date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '20:00',
            'timezone' => 'America/New_York',
            'lineup' => [['artist_id' => $artist->id]],
        ])->assertSessionHasNoErrors();

        $this->assertContains(NotificationType::ArtistShow->value, $this->typesIn($fan));
    }

    #[Test]
    public function being_added_to_a_bill_asks_the_invited_bands_members(): void
    {
        // The debt Phase 2 left: before this, the invitation sat in the
        // Artist Zone until someone happened to look -- and until it is
        // answered the show appears on no public surface.
        $host = Artist::factory()->create();
        $hostMember = $this->member($host);
        $support = Artist::factory()->create(['name' => 'Gutter Vows']);
        $supportMember = $this->member($support);

        $this->actingAs($hostMember)->post(route('shows.store'), [
            'host_artist_id' => $host->id,
            'title' => 'Two bands',
            'venue_id' => Venue::factory()->create()->id,
            'date' => now()->addDays(12)->format('Y-m-d'),
            'start_time' => '20:00',
            'timezone' => 'America/New_York',
            'lineup' => [['artist_id' => $host->id], ['artist_id' => $support->id]],
        ])->assertSessionHasNoErrors();

        $bell = $this->bell($supportMember);

        $this->assertCount(1, $bell);
        $this->assertSame(NotificationType::LineupInvite->value, $bell[0]['type']);
        $this->assertStringContainsString('Gutter Vows', $bell[0]['title']);
    }

    #[Test]
    public function a_pending_act_is_not_announced_to_its_fans_until_it_confirms(): void
    {
        // Announcing it would name an act that has not agreed to be named.
        $host = Artist::factory()->create();
        $hostMember = $this->member($host);
        $support = Artist::factory()->create();
        $supportMember = $this->member($support);
        $supportFan = $this->follower($support);

        $show = Show::factory()->for($host, 'hostArtist')->create(['starts_at' => now()->addDays(9)]);
        $entry = $show->lineup()->create(['artist_id' => $support->id, 'position' => 2]);
        $entry->forceFill(['status' => LineupEntry::PENDING])->save();

        $this->assertNotContains(NotificationType::ArtistShow->value, $this->typesIn($supportFan));

        $this->actingAs($supportMember)->post(route('lineup.confirm', $entry))->assertSessionHasNoErrors();

        $this->assertContains(NotificationType::ArtistShow->value, $this->typesIn($supportFan));
    }

    // ---------------------------------------------------------------
    // Preferences, mutes and blocks
    // ---------------------------------------------------------------

    #[Test]
    public function turning_a_kind_off_stops_it(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $member->forceFill(['notification_preferences' => [
            NotificationType::NewFollower->value => false,
        ]])->save();

        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $this->assertCount(0, $this->bell($member));
    }

    #[Test]
    public function a_new_account_gets_the_defaults(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->assertNull($member->notification_preferences);
        $this->assertTrue($member->wantsNotification(NotificationType::NewFollower));
    }

    #[Test]
    public function muting_one_artist_silences_only_that_artist(): void
    {
        $quiet = Artist::factory()->create();
        $loud = Artist::factory()->create();
        $fan = User::factory()->create();
        $fan->followedArtists()->attach([$quiet->id, $loud->id]);

        $this->actingAs($fan)->patch(route('artists.follow.notify', $quiet), ['notify' => false])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->member($quiet))->post(route('wall.posts.store', $quiet), [
            'content' => 'Quiet band', 'as_artist' => true,
        ]);
        $this->actingAs($this->member($loud))->post(route('wall.posts.store', $loud), [
            'content' => 'Loud band', 'as_artist' => true,
        ]);

        $titles = $fan->notifications()->get()->pluck('data.title')->all();

        $this->assertCount(1, $titles);
        $this->assertStringContainsString($loud->name, $titles[0]);
    }

    #[Test]
    public function a_mute_does_not_silence_a_reply_to_your_own_words(): void
    {
        // You were talking to a person, not subscribing to a band.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $fan = $this->follower($artist);

        $this->actingAs($fan)->patch(route('artists.follow.notify', $artist), ['notify' => false]);

        $post = Post::factory()->onWall($artist)->create(['user_id' => $fan->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.comments.store', $post), ['content' => 'Hello']);

        $this->assertContains(NotificationType::Reply->value, $this->typesIn($fan));
    }

    #[Test]
    public function muting_does_not_unfollow(): void
    {
        $artist = Artist::factory()->create();
        $fan = $this->follower($artist);

        $this->actingAs($fan)->patch(route('artists.follow.notify', $artist), ['notify' => false]);

        $this->assertTrue($fan->fresh()->follows($artist));
    }

    #[Test]
    public function muting_someone_you_do_not_follow_does_not_create_a_follow(): void
    {
        $artist = Artist::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->patch(route('artists.follow.notify', $artist), ['notify' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($stranger->fresh()->follows($artist));
    }

    #[Test]
    public function blocking_an_artist_suppresses_notifications_about_them(): void
    {
        // Blocking means "I do not want to see this artist". A
        // notification would walk straight through that.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $blocker = User::factory()->create();
        $blocker->followedArtists()->attach($artist);
        $blocker->blockedArtists()->attach($artist);

        $this->actingAs($member)->post(route('wall.posts.store', $artist), [
            'content' => 'Anyone there?', 'as_artist' => true,
        ]);

        $this->assertCount(0, $this->bell($blocker));
    }

    #[Test]
    public function the_lineup_invitation_cannot_be_turned_off(): void
    {
        // Switching it off would quietly break other people's listings.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs($member)->patch(route('notifications.preferences'), [
            'preferences' => [NotificationType::LineupInvite->value => false],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->wantsNotification(NotificationType::LineupInvite));
    }

    #[Test]
    public function preferences_can_be_saved_from_the_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('notifications.preferences'), [
            'preferences' => [
                NotificationType::ArtistPost->value => false,
                NotificationType::Reply->value => true,
            ],
        ])->assertSessionHasNoErrors();

        $fresh = $user->fresh();

        $this->assertFalse($fresh->wantsNotification(NotificationType::ArtistPost));
        $this->assertTrue($fresh->wantsNotification(NotificationType::Reply));
        // Anything the form did not send reads as off -- a toggle form
        // submits only what is on.
        $this->assertFalse($fresh->wantsNotification(NotificationType::NewFollower));
    }

    // ---------------------------------------------------------------
    // The bell
    // ---------------------------------------------------------------

    #[Test]
    public function the_unread_count_is_shared_with_every_page(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $this->actingAs($member)->get(route('scene.home'))
            ->assertInertia(fn ($page) => $page->where('notifications.unread', 1));
    }

    #[Test]
    public function a_guest_is_shared_no_notification_state(): void
    {
        $this->get(route('map.index'))
            ->assertInertia(fn ($page) => $page->where('notifications', null));
    }

    #[Test]
    public function marking_one_read_clears_it(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $notification = $member->notifications()->sole();

        $this->actingAs($member)->post(route('notifications.read', $notification->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $member->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function one_person_cannot_mark_anothers_notification_read(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $notification = $member->notifications()->sole();

        $this->actingAs(User::factory()->create())
            ->post(route('notifications.read', $notification->id))
            ->assertSessionHasNoErrors();

        // Silently nothing: the relation scopes it, so there is no id here
        // that reaches someone else's bell.
        $this->assertSame(1, $member->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function mark_all_read_clears_the_badge(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $artist->forceFill(['wall_mode' => 'everyone'])->save();

        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));
        $this->actingAs(User::factory()->create())
            ->post(route('wall.posts.store', $artist), ['content' => 'Hi']);

        $this->actingAs($member)->post(route('notifications.readAll'))->assertSessionHasNoErrors();

        $this->assertSame(0, $member->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function the_page_lists_notifications_and_the_preference_form(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $this->actingAs($member)->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notifications', 1)
                ->where('unreadCount', 1)
                ->has('preferences', count(NotificationType::cases())));
    }

    #[Test]
    public function the_bell_dropdown_fetches_the_recent_few(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->member($artist);
        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $response = $this->actingAs($member)->getJson(route('notifications.recent'));

        $response->assertOk();
        $response->assertJsonPath('unread', 1);
        $response->assertJsonCount(1, 'notifications');
        $this->assertNotNull($response->json('notifications.0.url'));
    }

    #[Test]
    public function the_dropdown_is_bounded_by_the_bell_limit(): void
    {
        // A scrolling panel of notifications is a feed in a smaller box.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $member = $this->member($artist);
        $limit = (int) config('riff.notifications.bell_limit');

        foreach (range(1, $limit + 3) as $i) {
            $this->actingAs(User::factory()->create())
                ->post(route('wall.posts.store', $artist), ['content' => "Hello {$i}"]);
        }

        $this->actingAs($member)->getJson(route('notifications.recent'))
            ->assertJsonCount($limit, 'notifications');
    }

    #[Test]
    public function a_reply_notification_links_to_the_reply_itself(): void
    {
        // "Someone answered you" is useless if you then have to go looking
        // down a long thread for it.
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $author = $this->follower($artist);
        $post = Post::factory()->onWall($artist)->create(['user_id' => $author->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.comments.store', $post), ['content' => 'Answering you']);

        $comment = Comment::sole();
        $url = $author->notifications()->sole()->data['url'];

        $this->assertStringEndsWith('#comment-'.$comment->id, $url);
        $this->assertStringContainsString(route('artists.show', $artist), $url);
    }

    #[Test]
    public function a_wall_post_notification_links_to_the_post_itself(): void
    {
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $member = $this->member($artist);

        $this->actingAs(User::factory()->create())
            ->post(route('wall.posts.store', $artist), ['content' => 'Hello']);

        $post = Post::sole();

        $this->assertStringEndsWith(
            '#post-'.$post->id,
            $member->notifications()->sole()->data['url'],
        );
    }

    #[Test]
    public function a_guest_cannot_reach_the_bell(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.recent'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_read_notification_does_not_fold_into(): void
    {
        // Folding is about an unread line. Once you have seen it, the next
        // one is news again.
        $artist = Artist::factory()->create();
        $member = $this->member($artist);

        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));
        $member->unreadNotifications->markAsRead();
        $this->actingAs(User::factory()->create())->post(route('artists.follow', $artist));

        $this->assertCount(2, $this->bell($member));
    }
}
