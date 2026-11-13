<?php

namespace Tests\Feature;

use App\Mail\WeeklyDigestMail;
use App\Models\Artist;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Services\DigestSender;
use App\Services\WeeklyDigest;
use Database\Factories\Support\Towns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The weekly digest: "your scene, by email".
 *
 * The rules under test are the ones that decide whether someone keeps
 * opening it: only people who asked, never twice in a week, and never an
 * empty one.
 */
class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private function subscriber(): User
    {
        $town = Towns::named('Hartford');

        return User::factory()->create([
            'city' => $town['city'],
            'state' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'digest_frequency' => 'weekly',
            'digest_sent_at' => null,
        ]);
    }

    private function followedArtistWithNews(User $user): Artist
    {
        $town = Towns::named('Hartford');
        $artist = Artist::factory()->create([
            'hometown' => $town['city'],
            'stateOrProvince' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
        ]);

        $user->followedArtists()->attach($artist);

        $member = User::factory()->create();
        $artist->members()->attach($member);
        Post::factory()->asArtist($artist, $member)->create([
            'content' => 'We finished the record',
            'published_at' => now()->subDay(),
        ]);

        return $artist;
    }

    private function sender(): DigestSender
    {
        return app(DigestSender::class);
    }

    // ---------------------------------------------------------------
    // Who gets one
    // ---------------------------------------------------------------

    #[Test]
    public function someone_with_news_gets_a_digest(): void
    {
        Mail::fake();

        $user = $this->subscriber();
        $this->followedArtistWithNews($user);

        $this->assertSame(1, $this->sender()->run());

        Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertNotNull($user->fresh()->digest_sent_at);
    }

    #[Test]
    public function nothing_is_sent_when_there_is_nothing_to_say(): void
    {
        // An empty digest is the fastest way to teach someone to
        // unsubscribe.
        Mail::fake();

        $user = $this->subscriber();

        $this->assertSame(0, $this->sender()->run());

        Mail::assertNothingQueued();
        // And the window is left alone, so it keeps growing until there
        // is something worth sending.
        $this->assertNull($user->fresh()->digest_sent_at);
    }

    #[Test]
    public function someone_who_turned_it_off_is_never_written_to(): void
    {
        Mail::fake();

        $user = $this->subscriber();
        $user->forceFill(['digest_frequency' => 'off'])->save();
        $this->followedArtistWithNews($user);

        $this->assertSame(0, $this->sender()->run());

        Mail::assertNothingQueued();
    }

    #[Test]
    public function an_unverified_account_is_never_written_to(): void
    {
        // Verification is what proves the address belongs to them.
        Mail::fake();

        $user = $this->subscriber();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->followedArtistWithNews($user);

        $this->assertSame(0, $this->sender()->run());

        Mail::assertNothingQueued();
    }

    #[Test]
    public function nobody_is_written_to_twice_inside_the_window(): void
    {
        // The cap lives on the data, so a scheduler that fires twice -- or
        // someone running the command by hand -- cannot double up.
        Mail::fake();

        $user = $this->subscriber();
        $this->followedArtistWithNews($user);

        $this->assertSame(1, $this->sender()->run());
        $this->assertSame(0, $this->sender()->run());

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function the_window_reopens_after_the_gap(): void
    {
        Mail::fake();

        $user = $this->subscriber();
        $this->followedArtistWithNews($user);

        $this->sender()->run();

        $user->forceFill([
            'digest_sent_at' => now()->subDays((int) config('riff.digest.min_days_between') + 1),
        ])->save();

        $this->assertSame(1, $this->sender()->run());
        Mail::assertQueuedCount(2);
    }

    // ---------------------------------------------------------------
    // What is in it
    // ---------------------------------------------------------------

    #[Test]
    public function it_carries_the_shows_you_said_you_were_going_to(): void
    {
        $user = $this->subscriber();
        $artist = Artist::factory()->create();
        $show = Show::factory()->for($artist, 'hostArtist')->create(['starts_at' => now()->addDays(4)]);

        $show->attendees()->attach($user->id, ['status' => 'going', 'is_public' => false]);

        $digest = app(WeeklyDigest::class)->build($user->fresh());

        $this->assertTrue($digest['myShows']->contains('id', $show->id));
    }

    #[Test]
    public function it_only_carries_updates_since_the_last_one(): void
    {
        // Otherwise a first digest would carry everything ever posted, and
        // every later one would repeat itself.
        $user = $this->subscriber();
        $artist = $this->followedArtistWithNews($user);
        $member = $artist->members()->first();

        Post::factory()->asArtist($artist, $member)->create([
            'content' => 'Ancient history',
            'published_at' => now()->subMonths(2),
        ]);

        $digest = app(WeeklyDigest::class)->build($user->fresh());
        $contents = $digest['updates']->pluck('content')->all();

        $this->assertContains('We finished the record', $contents);
        $this->assertNotContains('Ancient history', $contents);
    }

    #[Test]
    public function fan_posts_on_a_followed_wall_are_not_mailed_out(): void
    {
        // Mailing what strangers wrote on a band's wall to everyone who
        // follows that band is a stream of strangers in an inbox.
        $user = $this->subscriber();
        $artist = Artist::factory()->create(['wall_mode' => 'everyone']);
        $user->followedArtists()->attach($artist);

        Post::factory()->onWall($artist)->create([
            'user_id' => User::factory()->create()->id,
            'content' => 'A fan wrote this',
            'published_at' => now()->subHours(2),
        ]);

        $digest = app(WeeklyDigest::class)->build($user->fresh());

        $this->assertCount(0, $digest['updates']);
    }

    #[Test]
    public function a_blocked_artists_show_is_not_in_the_nearby_section(): void
    {
        $user = $this->subscriber();
        $blocked = Artist::factory()->create();
        Show::factory()->for($blocked, 'hostArtist')->oneOff('Hartford')
            ->create(['starts_at' => now()->addDays(3)]);

        $user->blockedArtists()->attach($blocked);

        $digest = app(WeeklyDigest::class)->build($user->fresh());

        $this->assertSame([], $digest['nearby']['shows']);
    }

    #[Test]
    public function the_mail_escapes_what_a_band_wrote(): void
    {
        // A plain HTML view, never a markdown mailable: markdown mail
        // parses the whole template, so a post could inject links into
        // every subscriber's inbox.
        Mail::fake();

        $user = $this->subscriber();
        $artist = $this->followedArtistWithNews($user);
        $member = $artist->members()->first();

        Post::factory()->asArtist($artist, $member)->create([
            'content' => '<script>alert(1)</script> [click](https://evil.example)',
            'published_at' => now()->subHour(),
        ]);

        $this->sender()->run();

        Mail::assertQueued(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
            $html = $mail->render();

            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            // The markdown link is left as the characters someone typed,
            // not turned into an anchor.
            $this->assertStringNotContainsString('href="https://evil.example"', $html);

            return true;
        });
    }

    #[Test]
    public function the_plain_text_part_is_not_html_escaped(): void
    {
        // text/plain has no HTML context to escape into, and Blade's
        // default escaping put "&amp;" in front of real people instead of
        // the "&" in their band's name.
        $user = $this->subscriber();
        $artist = Artist::factory()->create(['name' => 'Joey Batts & Them']);
        $user->followedArtists()->attach($artist);

        $member = User::factory()->create();
        $artist->members()->attach($member);
        Post::factory()->asArtist($artist, $member)->create([
            'content' => 'Tickets & merch at the door',
            'published_at' => now()->subHour(),
        ]);

        $text = view('mail.weekly-digest-text', [
            'name' => $user->name,
            ...app(WeeklyDigest::class)->build($user->fresh()),
            'updates' => app(WeeklyDigest::class)->build($user->fresh())['updates'],
            'nearbyShows' => [],
            'listings' => [],
            'sceneUrl' => route('scene.home'),
            'settingsUrl' => route('notifications.index'),
            'unsubscribeUrl' => 'https://example.com/stop',
        ])->render();

        $this->assertStringContainsString('Joey Batts & Them', $text);
        $this->assertStringContainsString('Tickets & merch', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    // ---------------------------------------------------------------
    // Stopping it
    // ---------------------------------------------------------------

    #[Test]
    public function the_unsubscribe_link_works_without_signing_in(): void
    {
        // Requiring a sign-in before someone can stop email is how
        // complaints become spam reports.
        $user = $this->subscriber();

        $this->get($this->sender()->unsubscribeUrl($user))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Digest/Unsubscribed')
                ->where('email', $user->email));

        $this->assertSame('off', $user->fresh()->digest_frequency);
    }

    #[Test]
    public function a_tampered_unsubscribe_link_is_refused(): void
    {
        $user = $this->subscriber();
        $other = $this->subscriber();

        $url = $this->sender()->unsubscribeUrl($user);
        $tampered = str_replace('/'.$user->id.'?', '/'.$other->id.'?', $url);

        $this->get($tampered)->assertForbidden();

        $this->assertSame('weekly', $other->fresh()->digest_frequency);
    }

    #[Test]
    public function unsubscribing_does_not_touch_the_account(): void
    {
        // Only the email stops. Someone who wanted quiet did not ask to
        // lose their follows.
        $user = $this->subscriber();
        $artist = $this->followedArtistWithNews($user);

        $this->get($this->sender()->unsubscribeUrl($user))->assertOk();

        $fresh = $user->fresh();

        $this->assertTrue($fresh->follows($artist));
        $this->assertNotNull($fresh->email_verified_at);
    }

    #[Test]
    public function the_frequency_can_be_changed_from_the_settings_page(): void
    {
        $user = $this->subscriber();

        $this->actingAs($user)->patch(route('notifications.preferences'), [
            'preferences' => [],
            'digest_frequency' => 'off',
        ])->assertSessionHasNoErrors();

        $this->assertSame('off', $user->fresh()->digest_frequency);
    }

    #[Test]
    public function an_unknown_frequency_is_refused(): void
    {
        $user = $this->subscriber();

        $this->actingAs($user)->patch(route('notifications.preferences'), [
            'preferences' => [],
            'digest_frequency' => 'hourly',
        ])->assertSessionHasErrors('digest_frequency');

        $this->assertSame('weekly', $user->fresh()->digest_frequency);
    }

    // ---------------------------------------------------------------
    // The command
    // ---------------------------------------------------------------

    #[Test]
    public function the_command_sends_and_reports(): void
    {
        Mail::fake();

        $user = $this->subscriber();
        $this->followedArtistWithNews($user);

        $this->artisan('riff:send-digests')
            ->expectsOutputToContain('Queued 1 digest')
            ->assertExitCode(0);

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function the_command_is_safe_to_run_twice(): void
    {
        Mail::fake();

        $user = $this->subscriber();
        $this->followedArtistWithNews($user);

        $this->artisan('riff:send-digests')->assertExitCode(0);
        $this->artisan('riff:send-digests')
            ->expectsOutputToContain('Nobody was due')
            ->assertExitCode(0);

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function the_batch_limit_bounds_one_run(): void
    {
        Mail::fake();

        $subscribers = collect(range(1, 3))->map(function () {
            $user = $this->subscriber();
            $this->followedArtistWithNews($user);

            return $user;
        });

        // The helper also creates the bands' member accounts, which are
        // themselves digest-eligible. Silence everyone who is not one of
        // the three under test, or "the first two by id" is not the first
        // two subscribers.
        User::whereNotIn('id', $subscribers->pluck('id'))
            ->update(['digest_frequency' => 'off']);

        $this->assertSame(2, $this->sender()->run(limit: 2));

        Mail::assertQueuedCount(2);
    }
}
