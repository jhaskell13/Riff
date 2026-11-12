<?php

namespace Tests\Feature;

use App\Jobs\GeocodeAddress;
use App\Mail\ListingReplyMail;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\GenreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Listings ("looking for"): local, expiring posts with replies relayed by
 * email, so nobody's address is exposed.
 */
class ListingTest extends TestCase
{
    use RefreshDatabase;

    private const AREA = ['south' => 41.5, 'west' => -73.0, 'north' => 42.0, 'east' => -72.4];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenreSeeder::class);
    }

    private function memberOf(Artist $artist): User
    {
        $user = User::factory()->create();
        $user->artists()->attach($artist);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'band_wanted',
            'title' => 'Bassist looking for a band',
            'body' => 'Fifteen years on bass, own gear, two nights a week.',
            'instruments' => ['bass'],
            'genres' => [Genre::where('name', 'Punk')->value('id')],
            'city' => 'Hartford',
            'state' => 'CT',
            'country' => 'USA',
        ], $overrides);
    }

    // ---- Posting -----------------------------------------------------------------

    public function test_a_person_posts_a_listing_that_runs_thirty_days_and_is_geocoded_by_town(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('listings.store'), $this->payload())->assertRedirect();

        $listing = Listing::firstOrFail();
        $this->assertSame($user->id, $listing->user_id);
        $this->assertNull($listing->artist_id);
        $this->assertTrue($listing->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame(['Hartford', 'CT', 'USA'], $listing->geocodableAddress());
        Queue::assertPushed(GeocodeAddress::class);
    }

    public function test_band_types_are_posted_as_an_artist_the_poster_belongs_to(): void
    {
        $band = Artist::factory()->create();
        $someoneElses = Artist::factory()->create();
        $member = $this->memberOf($band);

        $this->actingAs($member)
            ->post(route('listings.store'), $this->payload(['type' => 'members_wanted']))
            ->assertSessionHasErrors('artist_id');

        $this->actingAs($member)
            ->post(route('listings.store'), $this->payload(['type' => 'members_wanted', 'artist_id' => $someoneElses->id]))
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('listings.store'), $this->payload(['type' => 'members_wanted', 'artist_id' => $band->id]))
            ->assertRedirect();

        $this->assertSame($band->id, Listing::firstOrFail()->artist_id);
    }

    public function test_a_person_type_cannot_carry_an_artist(): void
    {
        $band = Artist::factory()->create();

        $this->actingAs($this->memberOf($band))
            ->post(route('listings.store'), $this->payload(['type' => 'band_wanted', 'artist_id' => $band->id]))
            ->assertSessionHasErrors('artist_id');
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('listings.store'), $this->payload([
            'user_id' => User::factory()->create()->id,
            'expires_at' => now()->addYears(5)->toDateTimeString(),
            'closed_at' => now()->toDateTimeString(),
        ]))->assertRedirect();

        $listing = Listing::firstOrFail();
        $this->assertSame($user->id, $listing->user_id);
        $this->assertTrue($listing->expires_at->lt(now()->addDays(31)));
        $this->assertNull($listing->closed_at);
    }

    public function test_unknown_vocabulary_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('listings.store'), $this->payload(['type' => 'groupies_wanted', 'instruments' => ['kazoo']]))
            ->assertSessionHasErrors(['type', 'instruments.0']);
    }

    public function test_posting_is_rate_limited(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < (int) config('riff.listings.rate_limit_per_minute'); $i++) {
            $this->actingAs($user)->post(route('listings.store'), $this->payload(['title' => "Listing number {$i}"]));
        }

        $this->actingAs($user)->post(route('listings.store'), $this->payload())->assertTooManyRequests();
    }

    // ---- Managing ------------------------------------------------------------------

    public function test_the_poster_and_the_artists_members_manage_a_listing(): void
    {
        $band = Artist::factory()->create();
        $poster = $this->memberOf($band);
        $bandmate = $this->memberOf($band);
        $listing = Listing::factory()->for($poster)->create(['type' => 'members_wanted']);
        $listing->forceFill(['artist_id' => $band->id])->save();

        $this->actingAs(User::factory()->create())->get(route('listings.edit', $listing))->assertForbidden();
        $this->actingAs($bandmate)->get(route('listings.edit', $listing))->assertOk();
        $this->actingAs($bandmate)->post(route('listings.close', $listing))->assertRedirect();

        $this->assertNotNull($listing->fresh()->closed_at);
    }

    public function test_the_type_cannot_change_on_edit(): void
    {
        $listing = Listing::factory()->create();

        $this->actingAs($listing->user)
            ->patch(route('listings.update', $listing), $this->payload(['type' => 'practice_space']))
            ->assertSessionHasErrors('type');
    }

    public function test_renewing_is_only_offered_near_or_after_the_end(): void
    {
        $fresh = Listing::factory()->create();
        $ending = Listing::factory()->create(['expires_at' => now()->addDays(3)]);
        $ended = Listing::factory()->expired()->create();

        $this->actingAs($fresh->user)->post(route('listings.renew', $fresh))->assertForbidden();
        $this->actingAs($ending->user)->post(route('listings.renew', $ending))->assertRedirect();
        $this->actingAs($ended->user)->post(route('listings.renew', $ended))->assertRedirect();

        $this->assertTrue($ended->fresh()->expires_at->gt(now()->addDays(29)));
    }

    // ---- Replies -------------------------------------------------------------------

    public function test_a_reply_is_relayed_by_email_without_exposing_either_address(): void
    {
        Mail::fake();
        $poster = User::factory()->create(['email' => 'poster@example.com']);
        $listing = Listing::factory()->for($poster)->create();
        $replier = User::factory()->create(['name' => 'Dana', 'email' => 'dana-secret@example.com']);

        $this->actingAs($replier)
            ->post(route('listings.replies.store', $listing), ['message' => 'I play drums and live nearby!', 'contact' => '@dana.drums'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Mail::assertQueued(ListingReplyMail::class, function (ListingReplyMail $mail) use ($poster) {
            $html = $mail->render();

            return $mail->hasTo($poster->email)
                && $mail->replyTo === []
                && str_contains($html, '@dana.drums')
                && ! str_contains($html, 'dana-secret@example.com');
        });

        $this->actingAs($replier)->get(route('listings.show', $listing))
            ->assertInertia(fn ($page) => $page->where('hasReplied', true)->where('canManage', false));
    }

    public function test_a_reply_cannot_inject_markup_into_the_email(): void
    {
        Mail::fake();
        $listing = Listing::factory()->create();

        $this->actingAs(User::factory()->create())->post(route('listings.replies.store', $listing), [
            'message' => '<a href="https://phish.example">click</a> [also](https://phish.example)',
            'contact' => 'me@example.com',
        ]);

        Mail::assertQueued(ListingReplyMail::class, function (ListingReplyMail $mail) {
            $html = $mail->render();

            return ! str_contains($html, '<a href="https://phish.example">') && str_contains($html, '&lt;a href=');
        });
    }

    public function test_one_reply_per_person_and_never_to_your_own_listing(): void
    {
        Mail::fake();
        $listing = Listing::factory()->create();
        $replier = User::factory()->create();
        $reply = ['message' => 'Interested — I play bass.', 'contact' => 'dana@example.com'];

        $this->actingAs($replier)->post(route('listings.replies.store', $listing), $reply);
        $this->actingAs($replier)->post(route('listings.replies.store', $listing), $reply)->assertSessionHasErrors('message');

        $this->actingAs($listing->user)->post(route('listings.replies.store', $listing), $reply)->assertForbidden();

        $this->assertSame(1, $listing->replies()->count());
        Mail::assertQueued(ListingReplyMail::class, 1);
    }

    public function test_a_contact_is_required_and_closed_listings_take_no_replies(): void
    {
        Mail::fake();
        $listing = Listing::factory()->create();
        $closed = Listing::factory()->closed()->create();
        $replier = User::factory()->create();

        // First, as a guest: actingAs lasts for the rest of the test.
        $this->post(route('listings.replies.store', $listing), ['message' => 'Interested in this!', 'contact' => 'x'])
            ->assertRedirect(route('login'));

        $this->actingAs($replier)
            ->post(route('listings.replies.store', $listing), ['message' => 'Interested in this!'])
            ->assertSessionHasErrors('contact');

        $this->actingAs($replier)
            ->post(route('listings.replies.store', $closed), ['message' => 'Interested in this!', 'contact' => 'x'])
            ->assertForbidden();
    }

    // ---- Public page and the map ---------------------------------------------------

    public function test_a_guest_reads_a_listing_but_sees_no_email(): void
    {
        $poster = User::factory()->create(['name' => 'Sam', 'email' => 'sam-private@example.com']);
        $listing = Listing::factory()->for($poster)->create();

        $response = $this->get(route('listings.show', $listing))->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Listings/Show')
            ->where('listing.postedBy.name', 'Sam')
            ->where('canReply', false)
            ->where('isGuest', true)
            ->where('replyCount', null));
        $this->assertStringNotContainsString('sam-private@example.com', $response->getContent());
    }

    public function test_only_active_listings_are_on_the_map(): void
    {
        $active = Listing::factory()->in('Hartford')->create();
        Listing::factory()->in('Hartford')->expired()->create();
        Listing::factory()->in('Hartford')->closed()->create();
        Listing::factory()->in('Providence')->create();

        $band = Artist::factory()->create();
        $archivedBands = Listing::factory()->in('Hartford')->create(['type' => 'members_wanted']);
        $archivedBands->forceFill(['artist_id' => $band->id])->save();
        $band->delete();

        $this->getJson(route('map.listings', self::AREA))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.kind', 'listing');
    }

    public function test_the_map_filters_listings_by_genre_and_hides_a_blocked_artists(): void
    {
        $punk = Genre::where('name', 'Punk')->value('id');
        $folk = Genre::where('name', 'Folk')->value('id');
        $punkListing = Listing::factory()->in('Hartford')->create();
        $punkListing->genres()->sync([$punk]);
        Listing::factory()->in('Hartford')->create()->genres()->sync([$folk]);

        $this->getJson(route('map.listings', [...self::AREA, 'genres' => 'punk']))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $punkListing->id);

        $band = Artist::factory()->create();
        $bands = Listing::factory()->in('Hartford')->create(['type' => 'members_wanted']);
        $bands->forceFill(['artist_id' => $band->id])->save();
        $viewer = User::factory()->create();
        $viewer->blockedArtists()->attach($band);

        $ids = collect($this->actingAs($viewer)->getJson(route('map.listings', self::AREA))->json('data'))->pluck('id');
        $this->assertNotContains($bands->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_artist_pages_and_the_zone_show_listings(): void
    {
        $band = Artist::factory()->create();
        $member = $this->memberOf($band);
        $listing = Listing::factory()->for($member)->create(['type' => 'members_wanted', 'title' => 'Drummer wanted now']);
        $listing->forceFill(['artist_id' => $band->id])->save();

        $this->get(route('artists.show', $band))
            ->assertInertia(fn ($page) => $page->where('listings.0.title', 'Drummer wanted now'));

        $this->actingAs($member)->get(route('artists.index'))
            ->assertInertia(fn ($page) => $page->where('listings.0.id', $listing->id)->where('listings.0.canRenew', false));
    }

    // ---- Moderation ----------------------------------------------------------------

    public function test_a_listing_can_be_reported_and_taken_down(): void
    {
        $listing = Listing::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('reports.store'), ['type' => 'listing', 'id' => $listing->id, 'reason' => 'spam'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->moderator()->create())
            ->post(route('moderation.resolve', Report::firstOrFail()), ['action' => 'remove_listing'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($listing);
        $this->get(route('listings.show', $listing))->assertNotFound();
    }

    // ---- Audit regressions ---------------------------------------------------

    public function test_a_listing_cannot_be_reattributed_on_edit(): void
    {
        $band = Artist::factory()->create();
        $otherBand = Artist::factory()->create();
        $poster = $this->memberOf($band);
        $bandmate = $this->memberOf($band);
        $bandmate->artists()->attach($otherBand);
        $listing = Listing::factory()->for($poster)->create(['type' => 'practice_space']);
        $listing->forceFill(['artist_id' => $band->id])->save();

        $this->actingAs($bandmate)
            ->patch(route('listings.update', $listing), $this->payload(['type' => 'practice_space', 'artist_id' => $otherBand->id]))
            ->assertSessionHasErrors('artist_id');

        $this->actingAs($bandmate)
            ->patch(route('listings.update', $listing), $this->payload(['type' => 'practice_space', 'artist_id' => null]))
            ->assertSessionHasErrors('artist_id');

        $this->assertSame($band->id, $listing->fresh()->artist_id);
    }

    public function test_an_archived_artists_listing_never_shows_the_members_name(): void
    {
        $band = Artist::factory()->create();
        $poster = User::factory()->create(['name' => 'Private Person']);
        $poster->artists()->attach($band);
        $listing = Listing::factory()->for($poster)->create(['type' => 'members_wanted']);
        $listing->forceFill(['artist_id' => $band->id])->save();
        $band->delete();

        $this->get(route('listings.show', $listing))
            ->assertInertia(fn ($page) => $page->where('listing.postedBy.name', 'An archived artist'));
    }

    public function test_a_poster_who_left_the_band_can_still_edit_their_listing(): void
    {
        $band = Artist::factory()->create();
        $poster = $this->memberOf($band);
        $listing = Listing::factory()->for($poster)->create(['type' => 'members_wanted']);
        $listing->forceFill(['artist_id' => $band->id])->save();
        $poster->artists()->detach($band);

        $this->actingAs($poster)
            ->patch(route('listings.update', $listing), $this->payload(['type' => 'members_wanted', 'artist_id' => $band->id, 'title' => 'Drummer still wanted']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Drummer still wanted', $listing->fresh()->title);
    }
}
