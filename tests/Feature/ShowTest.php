<?php

namespace Tests\Feature;

use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\LineupEntry;
use App\Models\Report;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Shows: listed by a host artist's members, public to everyone, with
 * lineup consent — another Riff artist appears on a bill only once its own
 * members confirm — and a place that is either a venue or a one-off
 * location of the show's own.
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

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
    private function payload(Artist $host, array $overrides = []): array
    {
        return array_merge([
            'host_artist_id' => $host->id,
            // Only when the test doesn't choose its own place.
            'venue_id' => array_key_exists('venue_id', $overrides) ? null : Venue::factory()->create()->id,
            'date' => now('America/New_York')->addDays(10)->format('Y-m-d'),
            'start_time' => '20:00',
            'timezone' => 'America/New_York',
            'lineup' => [['artist_id' => $host->id]],
        ], $overrides);
    }

    /**
     * The update payload: the store payload minus the host, which is fixed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editPayload(Show $show, array $overrides = []): array
    {
        $start = $show->localStart();

        return array_merge([
            'venue_id' => $show->venue_id,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'timezone' => $show->timezone,
            'lineup' => $show->lineup()->get()->map(fn (LineupEntry $entry) => $entry->artist_id
                ? ['artist_id' => $entry->artist_id]
                : ['name' => $entry->name])->all(),
        ], $overrides);
    }

    // ---- Listing a show ------------------------------------------------------

    public function test_a_member_lists_a_show_and_the_time_is_stored_in_utc(): void
    {
        $host = Artist::factory()->create();
        $member = $this->memberOf($host);
        $date = now('America/New_York')->addDays(10)->format('Y-m-d');

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['date' => $date, 'start_time' => '20:00', 'doors_time' => '19:00']))
            ->assertRedirect();

        $show = Show::firstOrFail();
        $expected = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} 20:00", 'America/New_York')->utc();

        $this->assertTrue($show->starts_at->equalTo($expected));
        $this->assertSame('20:00', $show->localStart()->format('H:i'));
        $this->assertSame('19:00', $show->localDoors()?->format('H:i'));
        $this->assertSame($host->id, $show->host_artist_id);
        $this->assertSame($member->id, $show->created_by);
        $this->assertSame(Show::SOURCE_MEMBER, $show->source);
    }

    public function test_lineup_entries_start_in_the_right_state(): void
    {
        $host = Artist::factory()->create();
        $sideProject = Artist::factory()->create();
        $stranger = Artist::factory()->create();
        $member = $this->memberOf($host);
        $member->artists()->attach($sideProject);

        $this->actingAs($member)->post(route('shows.store'), $this->payload($host, [
            'lineup' => [
                ['artist_id' => $host->id],
                ['artist_id' => $stranger->id],
                ['artist_id' => $sideProject->id],
                ['name' => 'The Unlisted'],
            ],
        ]))->assertRedirect();

        $lineup = Show::firstOrFail()->lineup()->get();

        // Membership is consent; anyone else is asked.
        $this->assertSame(
            [LineupEntry::CONFIRMED, LineupEntry::PENDING, LineupEntry::CONFIRMED, LineupEntry::CONFIRMED],
            $lineup->pluck('status')->all(),
        );
        $this->assertSame([0, 1, 2, 3], $lineup->pluck('position')->all());
    }

    public function test_only_members_can_host_and_only_for_their_own_artist(): void
    {
        $host = Artist::factory()->create();
        $someoneElsesBand = Artist::factory()->create();
        $member = $this->memberOf($host);

        $this->actingAs(User::factory()->create())
            ->get(route('shows.create'))
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($someoneElsesBand))
            ->assertForbidden();

        $this->assertDatabaseCount('shows', 0);
    }

    public function test_the_host_has_to_be_on_the_bill(): void
    {
        $host = Artist::factory()->create();

        $this->actingAs($this->memberOf($host))
            ->post(route('shows.store'), $this->payload($host, ['lineup' => [['name' => 'Someone Else']]]))
            ->assertSessionHasErrors('lineup');
    }

    public function test_a_show_needs_a_venue_or_a_one_off_location_but_not_both(): void
    {
        $host = Artist::factory()->create();
        $member = $this->memberOf($host);

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['venue_id' => null]))
            ->assertSessionHasErrors(['location_name', 'city', 'state']);

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, [
                'location_name' => 'Backyard', 'city' => 'Hartford', 'state' => 'CT',
            ]))
            ->assertSessionHasErrors('location_name');
    }

    public function test_a_one_off_show_is_geocoded_at_town_level_and_never_becomes_a_venue(): void
    {
        Queue::fake();
        $host = Artist::factory()->create();

        $this->actingAs($this->memberOf($host))->post(route('shows.store'), $this->payload($host, [
            'venue_id' => null,
            'location_name' => 'Backyard on the west side',
            'city' => 'Hartford',
            'state' => 'CT',
            'country' => 'USA',
            'location_note' => 'Ask a punk',
        ]))->assertRedirect();

        $show = Show::firstOrFail();

        $this->assertTrue($show->is_one_off);
        $this->assertSame(['Hartford', 'CT', 'USA'], $show->geocodableAddress());
        Queue::assertPushed(GeocodeAddress::class, fn (GeocodeAddress $job) => $job->model->is($show));
        $this->assertDatabaseCount('venues', 0);
    }

    public function test_a_venue_show_keeps_no_location_of_its_own_and_is_not_geocoded(): void
    {
        $host = Artist::factory()->create();
        $venue = Venue::factory()->create();
        Queue::fake();

        $show = Show::factory()->create(['host_artist_id' => $host->id, 'venue_id' => $venue->id, 'city' => 'Stray', 'lat' => 1.0]);

        $this->assertNull($show->city);
        $this->assertNull($show->lat);
        Queue::assertNotPushed(GeocodeAddress::class);
    }

    public function test_times_must_make_sense(): void
    {
        $host = Artist::factory()->create();
        $member = $this->memberOf($host);

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['date' => now()->subDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('date');

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['date' => now()->addYears(2)->format('Y-m-d')]))
            ->assertSessionHasErrors('date');

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['start_time' => '20:00', 'doors_time' => '21:00']))
            ->assertSessionHasErrors('doors_time');

        $this->actingAs($member)
            ->post(route('shows.store'), $this->payload($host, ['timezone' => 'Mars/Olympus_Mons']))
            ->assertSessionHasErrors('timezone');
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $host = Artist::factory()->create();
        $member = $this->memberOf($host);

        $this->actingAs($member)->post(route('shows.store'), $this->payload($host, [
            'created_by' => User::factory()->create()->id,
            'source' => 'imported',
            'cancelled_at' => now()->toDateTimeString(),
            'lineup' => [['artist_id' => $host->id, 'status' => 'confirmed']],
        ]))->assertSessionHasErrors('lineup.0');

        $this->actingAs($member)->post(route('shows.store'), $this->payload($host, [
            'created_by' => User::factory()->create()->id,
            'source' => 'imported',
            'cancelled_at' => now()->toDateTimeString(),
        ]))->assertRedirect();

        $show = Show::firstOrFail();
        $this->assertSame($member->id, $show->created_by);
        $this->assertSame(Show::SOURCE_MEMBER, $show->source);
        $this->assertNull($show->cancelled_at);
    }

    // ---- The public page -----------------------------------------------------

    public function test_a_guest_sees_only_confirmed_acts(): void
    {
        $host = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $asked = Artist::factory()->create(['name' => 'Sewer Psalms']);
        $show = Show::factory()->create(['host_artist_id' => $host->id]);
        $show->lineup()->make(['artist_id' => $asked->id, 'position' => 1])
            ->forceFill(['status' => LineupEntry::PENDING])->save();

        $this->get(route('shows.show', $show))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shows/Show')
                ->where('show.title', 'Gutter Hymns')
                ->has('show.lineup', 1)
                ->where('show.lineup.0.name', 'Gutter Hymns')
                ->where('canManage', false)
                ->where('canRsvp', false)
                ->where('isGuest', true));
    }

    public function test_times_render_in_the_shows_timezone(): void
    {
        $show = Show::factory()->create([
            'starts_at' => CarbonImmutable::parse('2027-03-05 20:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);

        $this->travelTo(CarbonImmutable::parse('2027-03-01'));

        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->where('show.dateLabel', 'Friday, March 5, 2027')
                ->where('show.timeLabel', '8:00 PM')
                ->where('show.timezoneLabel', 'PST'));
    }

    // ---- Lineup consent ------------------------------------------------------

    public function test_an_invited_artist_confirms_and_then_appears(): void
    {
        $asked = Artist::factory()->create(['name' => 'Sewer Psalms']);
        $show = Show::factory()->create();
        $entry = $show->lineup()->make(['artist_id' => $asked->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::PENDING])->save();

        // The invite is in their zone.
        $this->actingAs($member = $this->memberOf($asked))
            ->get(route('artists.index'))
            ->assertInertia(fn ($page) => $page
                ->has('showInvites', 1)
                ->where('showInvites.0.id', $entry->id));

        $this->actingAs($member)->post(route('lineup.confirm', $entry))->assertRedirect();

        $this->assertSame(LineupEntry::CONFIRMED, $entry->fresh()->status);
        $this->get(route('shows.show', $show))->assertInertia(fn ($page) => $page->has('show.lineup', 2));
        $this->get(route('artists.show', $asked))->assertInertia(fn ($page) => $page->has('upcomingShows', 1));
    }

    public function test_declining_keeps_an_artist_off_the_bill(): void
    {
        $asked = Artist::factory()->create();
        $show = Show::factory()->create();
        $entry = $show->lineup()->make(['artist_id' => $asked->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::CONFIRMED])->save();

        $this->actingAs($this->memberOf($asked))->post(route('lineup.decline', $entry))->assertRedirect();

        $this->assertSame(LineupEntry::DECLINED, $entry->fresh()->status);
        $this->get(route('shows.show', $show))->assertInertia(fn ($page) => $page->has('show.lineup', 1));
        $this->get(route('artists.show', $asked))->assertInertia(fn ($page) => $page->has('upcomingShows', 0));
    }

    public function test_only_the_named_artists_members_can_answer(): void
    {
        $asked = Artist::factory()->create();
        $show = Show::factory()->create();
        $entry = $show->lineup()->make(['artist_id' => $asked->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::PENDING])->save();

        // Not even the host can confirm on another band's behalf.
        $hostMember = $this->memberOf($show->hostArtist);

        $this->actingAs($hostMember)->post(route('lineup.confirm', $entry))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('lineup.confirm', $entry))->assertForbidden();
        $this->assertSame(LineupEntry::PENDING, $entry->fresh()->status);
    }

    public function test_the_host_cannot_decline_its_own_show(): void
    {
        $show = Show::factory()->create();
        $hostEntry = $show->lineup()->firstOrFail();

        $this->actingAs($this->memberOf($show->hostArtist))
            ->post(route('lineup.decline', $hostEntry))
            ->assertForbidden();
    }

    // ---- Editing ---------------------------------------------------------------

    public function test_moving_a_show_asks_the_other_artists_again(): void
    {
        $show = Show::factory()->create();
        $other = Artist::factory()->create();
        $entry = $show->lineup()->make(['artist_id' => $other->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::CONFIRMED])->save();
        $member = $this->memberOf($show->hostArtist);

        // A typo fix is not a new show.
        $this->actingAs($member)
            ->patch(route('shows.update', $show), $this->editPayload($show, ['price' => '$12']))
            ->assertRedirect();
        $this->assertSame(LineupEntry::CONFIRMED, $entry->fresh()->status);

        // A new date is.
        $newDate = $show->localStart()->addDay()->format('Y-m-d');
        $this->actingAs($member)
            ->patch(route('shows.update', $show->fresh()), $this->editPayload($show->fresh(), ['date' => $newDate]))
            ->assertRedirect();

        $this->assertSame(LineupEntry::PENDING, $entry->fresh()->status);
        // The host's own slot stays confirmed.
        $this->assertSame(LineupEntry::CONFIRMED, $show->lineup()->where('artist_id', $show->host_artist_id)->value('status'));
    }

    public function test_a_declined_artist_is_not_re_asked_by_an_ordinary_edit(): void
    {
        $show = Show::factory()->create();
        $other = Artist::factory()->create();
        $entry = $show->lineup()->make(['artist_id' => $other->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::DECLINED])->save();

        $this->actingAs($this->memberOf($show->hostArtist))
            ->patch(route('shows.update', $show), $this->editPayload($show, ['description' => 'Bring earplugs.']))
            ->assertRedirect();

        $this->assertSame(LineupEntry::DECLINED, $entry->fresh()->status);
    }

    public function test_removing_an_act_drops_its_entry(): void
    {
        $show = Show::factory()->create();
        $other = Artist::factory()->create();
        $show->lineup()->make(['artist_id' => $other->id, 'position' => 1])
            ->forceFill(['status' => LineupEntry::CONFIRMED])->save();

        $this->actingAs($this->memberOf($show->hostArtist))
            ->patch(route('shows.update', $show), $this->editPayload($show, [
                'lineup' => [['artist_id' => $show->host_artist_id], ['name' => 'Fill-in Band']],
            ]))
            ->assertRedirect();

        $this->assertSame(['confirmed', 'confirmed'], $show->lineup()->pluck('status')->all());
        $this->assertNull($show->lineup()->where('artist_id', $other->id)->first());
    }

    public function test_only_host_members_edit_and_the_host_never_changes(): void
    {
        $show = Show::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('shows.edit', $show))->assertForbidden();
        $this->actingAs($stranger)
            ->patch(route('shows.update', $show), $this->editPayload($show))
            ->assertForbidden();

        $this->actingAs($this->memberOf($show->hostArtist))
            ->patch(route('shows.update', $show), $this->editPayload($show, ['host_artist_id' => Artist::factory()->create()->id]))
            ->assertSessionHasErrors('host_artist_id');
    }

    // ---- Cancelling, listings, archived hosts --------------------------------

    public function test_a_cancelled_show_keeps_its_page_but_leaves_the_listings(): void
    {
        $show = Show::factory()->create();
        $member = $this->memberOf($show->hostArtist);

        $this->actingAs($member)->post(route('shows.cancel', $show))->assertRedirect();

        $this->get(route('shows.show', $show))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('show.isCancelled', true));
        $this->get(route('artists.show', $show->hostArtist))->assertInertia(fn ($page) => $page->has('upcomingShows', 0));
        $this->get(route('venues.show', $show->venue))->assertInertia(fn ($page) => $page->has('upcomingShows', 0));
        $this->actingAs($member)->get(route('shows.edit', $show))->assertForbidden();
    }

    public function test_listings_show_upcoming_shows_only(): void
    {
        $venue = Venue::factory()->create();
        $host = Artist::factory()->create();
        Show::factory()->create(['host_artist_id' => $host->id, 'venue_id' => $venue->id]);
        Show::factory()->past()->create(['host_artist_id' => $host->id, 'venue_id' => $venue->id]);
        // Tonight's show is still on a couple of hours after it started.
        Show::factory()->create(['host_artist_id' => $host->id, 'venue_id' => $venue->id, 'starts_at' => now()->subHours(2)]);

        $this->get(route('artists.show', $host))->assertInertia(fn ($page) => $page->has('upcomingShows', 2));
        $this->get(route('venues.show', $venue))->assertInertia(fn ($page) => $page->has('upcomingShows', 2));
    }

    public function test_an_archived_hosts_shows_leave_the_listings(): void
    {
        $venue = Venue::factory()->create();
        $show = Show::factory()->create(['venue_id' => $venue->id]);
        $show->hostArtist->delete();

        $this->get(route('venues.show', $venue))->assertInertia(fn ($page) => $page->has('upcomingShows', 0));
    }

    // ---- Going / interested ----------------------------------------------------

    public function test_rsvps_are_private_by_default(): void
    {
        $show = Show::factory()->create();
        $quiet = User::factory()->create(['name' => 'Quiet Fan']);
        $loud = User::factory()->create(['name' => 'Loud Fan']);

        $this->actingAs($quiet)->post(route('shows.rsvp', $show), ['status' => 'going'])->assertRedirect();
        $this->actingAs($loud)->post(route('shows.rsvp', $show), ['status' => 'going', 'is_public' => true])->assertRedirect();
        $this->actingAs(User::factory()->create())->post(route('shows.rsvp', $show), ['status' => 'interested']);

        $this->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->where('rsvp.going', 2)
                ->where('rsvp.interested', 1)
                ->where('rsvp.publicGoing', ['Loud Fan']));

        $this->actingAs($quiet)
            ->get(route('shows.show', $show))
            ->assertInertia(fn ($page) => $page
                ->where('rsvp.mine.status', 'going')
                ->where('rsvp.mine.isPublic', false));
    }

    public function test_an_rsvp_can_be_changed_and_withdrawn(): void
    {
        $show = Show::factory()->create();
        $fan = User::factory()->create();

        $this->actingAs($fan)->post(route('shows.rsvp', $show), ['status' => 'interested']);
        $this->actingAs($fan)->post(route('shows.rsvp', $show), ['status' => 'going']);
        $this->assertSame(1, $show->attendees()->count());
        $this->assertSame('going', $show->attendees()->first()?->pivot->getAttribute('status'));

        $this->actingAs($fan)->delete(route('shows.unrsvp', $show))->assertRedirect();
        $this->assertSame(0, $show->attendees()->count());
    }

    public function test_no_rsvps_for_past_or_cancelled_shows_or_guests(): void
    {
        $past = Show::factory()->past()->create();
        $cancelled = Show::factory()->cancelled()->create();

        $this->post(route('shows.rsvp', $past), ['status' => 'going'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('shows.rsvp', $past), ['status' => 'going'])->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('shows.rsvp', $cancelled), ['status' => 'going'])->assertForbidden();
    }

    // ---- Moderation and search -------------------------------------------------

    public function test_a_show_can_be_reported_and_taken_down(): void
    {
        $show = Show::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('reports.store'), ['type' => 'show', 'id' => $show->id, 'reason' => 'spam'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $report = Report::firstOrFail();

        $this->actingAs(User::factory()->moderator()->create())
            ->get(route('moderation.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reports.data.0.subject.kind', 'show')
                ->where('reports.data.0.actions.0.value', 'remove_show'));

        $this->actingAs(User::factory()->moderator()->create())
            ->post(route('moderation.resolve', $report), ['action' => 'remove_show'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($show);
        $this->get(route('shows.show', $show))->assertNotFound();
    }

    public function test_lineup_search_finds_artists_hidden_from_the_map(): void
    {
        Artist::factory()->create(['name' => 'Gutter Hymns', 'show_on_map' => false]);
        Artist::factory()->create(['name' => 'Unrelated']);

        $this->getJson(route('search.artists', ['q' => 'gutter']))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson(route('search.artists', ['q' => 'GUTTER']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Gutter Hymns');
    }
}
