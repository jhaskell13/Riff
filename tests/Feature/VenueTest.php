<?php

namespace Tests\Feature;

use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use App\Services\Geocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Venues: established places that host music regularly. Community-submitted,
 * published at once, moderated after the fact — and a private venue's
 * street address is never stored, so it can never be shown.
 */
class VenueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'The Lantern Room',
            'kind' => 'club',
            'address_visibility' => 'public',
            'street_address' => '12 Pratt St',
            'city' => 'Hartford',
            'state' => 'CT',
            'country' => 'USA',
            'age_policy' => 'all_ages',
            'website' => '',
            'description' => '',
        ], $overrides);
    }

    // ---- Public pages ------------------------------------------------------

    public function test_a_guest_can_view_a_venue(): void
    {
        $venue = Venue::factory()->create(['name' => 'The Lantern Room', 'street_address' => '12 Pratt St', 'city' => 'Hartford', 'state' => 'CT']);

        $this->get(route('venues.show', $venue))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Venues/Show')
                ->where('venue.name', 'The Lantern Room')
                ->where('venue.address', '12 Pratt St, Hartford, CT')
                ->where('venue.isPrivate', false)
                ->where('canEdit', false)
                ->where('canReport', false));
    }

    public function test_a_private_venue_page_shows_only_the_town(): void
    {
        $venue = Venue::factory()->diy()->create(['name' => 'Basement Signal', 'city' => 'Hartford', 'state' => 'CT']);

        $this->get(route('venues.show', $venue))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('venue.address', 'Hartford, CT')
                ->where('venue.isPrivate', true)
                ->missing('venue.street_address')
                ->missing('venue.streetAddress'));
    }

    public function test_a_renamed_venue_redirects_its_old_url(): void
    {
        $venue = Venue::factory()->create(['name' => 'Copper Tap']);
        $oldUrl = route('venues.show', $venue);

        $venue->update(['name' => 'Copper Tap & Stage']);

        $this->get($oldUrl)
            ->assertMovedPermanently()
            ->assertRedirect(route('venues.show', $venue));
    }

    public function test_a_taken_down_venue_is_a_404(): void
    {
        $venue = Venue::factory()->create();
        $url = route('venues.show', $venue);
        $venue->delete();

        $this->get($url)->assertNotFound();
    }

    public function test_names_that_collide_with_routes_get_a_suffix(): void
    {
        // venues/create and artists/create are routes, so a venue or band
        // called "Create" would otherwise be unreachable.
        $venue = Venue::factory()->create(['name' => 'Create']);
        $artist = Artist::factory()->create(['name' => 'Create', 'artist_type' => 'band']);

        $this->assertSame('create-venue', $venue->slug);
        $this->assertSame('create-band', $artist->slug);
        $this->get(route('venues.show', $venue))->assertOk();
        $this->get(route('artists.show', $artist))->assertOk();
    }

    // ---- Submission --------------------------------------------------------

    public function test_guests_and_unverified_users_cannot_add_venues(): void
    {
        $this->get(route('venues.create'))->assertRedirect(route('login'));
        $this->post(route('venues.store'), $this->payload())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->post(route('venues.store'), $this->payload())
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('venues', 0);
    }

    public function test_a_user_can_add_a_venue_and_it_is_geocoded_at_the_street(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('venues.store'), $this->payload())
            ->assertRedirect();

        $venue = Venue::firstOrFail();

        $this->assertSame($user->id, $venue->submitted_by);
        $this->assertSame('the-lantern-room', $venue->slug);
        $this->assertTrue($venue->show_on_map);
        Queue::assertPushed(GeocodeAddress::class);
        $this->assertSame(['Hartford', 'CT', 'USA', '12 Pratt St'], $venue->geocodableAddress());
    }

    public function test_a_public_venue_needs_a_street_address(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('venues.store'), $this->payload(['street_address' => '']))
            ->assertSessionHasErrors('street_address');
    }

    public function test_a_private_venue_never_stores_a_street_even_if_one_is_sent(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('venues.store'), $this->payload([
                'name' => 'Basement Signal',
                'kind' => 'diy',
                'address_visibility' => 'private',
                'street_address' => '44 Somebody Lane',
            ]))
            ->assertRedirect();

        $venue = Venue::firstOrFail();

        $this->assertNull($venue->street_address);
        $this->assertSame(['Hartford', 'CT', 'USA'], $venue->geocodableAddress());
    }

    public function test_the_model_enforces_privacy_without_the_form(): void
    {
        // Seeders, tinker, a future import: none may store a private street.
        $venue = Venue::factory()->create(['street_address' => '12 Pratt St']);

        $venue->update(['address_visibility' => 'private']);

        $this->assertNull($venue->fresh()->street_address);
    }

    public function test_going_private_clears_the_pin_and_regeocodes_at_town_level(): void
    {
        config(['services.google.maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => 41.7658, 'lng' => -72.6734]]]],
            ]),
        ]);

        $venue = Venue::factory()->create(['street_address' => '12 Pratt St', 'city' => 'Hartford', 'state' => 'CT', 'lat' => 41.7661, 'lng' => -72.6745]);

        Queue::fake();
        $venue->update(['address_visibility' => 'private']);

        // Off the map until the town-level geocode lands: the old
        // street-level point must not survive the change.
        $this->assertNull($venue->fresh()->lat);
        Queue::assertPushed(GeocodeAddress::class);

        (new GeocodeAddress($venue->fresh()))->handle(app(Geocoder::class));

        Http::assertSent(fn (HttpRequest $request) => $request['address'] === 'Hartford, CT, USA');
        Http::assertNotSent(fn (HttpRequest $request) => str_contains((string) $request['address'], 'Pratt'));
        $this->assertSame(41.7658, $venue->fresh()->lat);
    }

    public function test_the_same_venue_cannot_be_added_twice_in_one_town(): void
    {
        Venue::factory()->create(['name' => 'The Lantern Room', 'city' => 'Hartford', 'state' => 'CT']);

        $this->actingAs(User::factory()->create())
            ->post(route('venues.store'), $this->payload(['name' => 'the lantern room ']))
            ->assertSessionHasErrors('name');

        // Same name, different town: a different place.
        $this->actingAs(User::factory()->create())
            ->post(route('venues.store'), $this->payload(['city' => 'New Haven']))
            ->assertSessionHasNoErrors();
    }

    public function test_protected_columns_cannot_be_mass_assigned(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->actingAs($user)->post(route('venues.store'), $this->payload([
            'slug' => 'hijacked',
            'submitted_by' => $someoneElse->id,
            'show_on_map' => false,
            'lat' => 1,
            'lng' => 1,
        ]));

        $venue = Venue::firstOrFail();

        $this->assertSame('the-lantern-room', $venue->slug);
        $this->assertSame($user->id, $venue->submitted_by);
        $this->assertTrue($venue->show_on_map);
        $this->assertNull($venue->lat);
    }

    public function test_an_unknown_kind_or_age_policy_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('venues.store'), $this->payload(['kind' => 'backyard', 'age_policy' => 'toddlers']))
            ->assertSessionHasErrors(['kind', 'age_policy']);
    }

    public function test_venue_creation_is_rate_limited(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $limit = (int) config('riff.venues.rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($user)->post(route('venues.store'), $this->payload(['name' => "Venue {$i}"]));
        }

        $this->actingAs($user)
            ->post(route('venues.store'), $this->payload(['name' => 'One too many']))
            ->assertTooManyRequests();
    }

    // ---- Editing -----------------------------------------------------------

    public function test_only_the_submitter_can_edit(): void
    {
        $venue = Venue::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('venues.edit', $venue))->assertForbidden();
        $this->actingAs($stranger)
            ->patch(route('venues.update', $venue), $this->payload(['name' => 'Taken Over']))
            ->assertForbidden();

        $this->actingAs($venue->submitter)
            ->patch(route('venues.update', $venue), $this->payload(['name' => 'Renamed Room']))
            ->assertRedirect();

        $this->assertSame('Renamed Room', $venue->fresh()->name);
    }

    public function test_the_submitter_sees_the_edit_control(): void
    {
        $venue = Venue::factory()->create();

        $this->actingAs($venue->submitter)
            ->get(route('venues.show', $venue))
            ->assertInertia(fn ($page) => $page->where('canEdit', true)->where('canReport', true));
    }

    public function test_editing_does_not_trip_the_duplicate_check_against_itself(): void
    {
        $venue = Venue::factory()->create($this->payload());

        $this->actingAs($venue->submitter)
            ->patch(route('venues.update', $venue), $this->payload(['description' => 'Two hundred cap.']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_submitter_cannot_undo_a_moderators_hide(): void
    {
        $venue = Venue::factory()->create();
        $venue->forceFill(['show_on_map' => false])->save();

        $this->actingAs($venue->submitter)
            ->patch(route('venues.update', $venue), $this->payload(['show_on_map' => true]));

        $this->assertFalse($venue->fresh()->show_on_map);
    }

    // ---- Moderation --------------------------------------------------------

    public function test_a_venue_can_be_reported(): void
    {
        $venue = Venue::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('reports.store'), ['type' => 'venue', 'id' => $venue->id, 'reason' => 'spam'])
            // assertSessionHasNoErrors alone passes on a 500.
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $venue->reports()->count());
    }

    public function test_a_moderator_can_hide_a_venue_and_its_page_stays(): void
    {
        $venue = Venue::factory()->create();
        $report = Report::factory()->about($venue)->create();

        $this->actingAs(User::factory()->moderator()->create())
            ->post(route('moderation.resolve', $report), ['action' => 'hide_venue'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($venue->fresh()->show_on_map);
        $this->get(route('venues.show', $venue))->assertOk();
    }

    public function test_a_moderator_can_take_a_venue_down_recoverably(): void
    {
        $venue = Venue::factory()->create();
        $report = Report::factory()->about($venue)->create();

        $this->actingAs(User::factory()->moderator()->create())
            ->post(route('moderation.resolve', $report), ['action' => 'archive_venue'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($venue);
        $this->get(route('venues.show', $venue))->assertNotFound();
    }

    public function test_artist_actions_do_not_apply_to_venues(): void
    {
        $venue = Venue::factory()->create();
        $report = Report::factory()->about($venue)->create();

        $this->actingAs(User::factory()->moderator()->create())
            ->post(route('moderation.resolve', $report), ['action' => 'hide_artist'])
            ->assertSessionHasErrors('action');
    }

    public function test_the_queue_summarises_a_venue_without_a_private_street(): void
    {
        $venue = Venue::factory()->diy()->create(['name' => 'Basement Signal', 'city' => 'Hartford', 'state' => 'CT']);
        Report::factory()->about($venue)->create();

        $this->actingAs(User::factory()->moderator()->create())
            ->get(route('moderation.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reports.data.0.subject.kind', 'venue')
                ->where('reports.data.0.subject.title', 'Basement Signal')
                ->where('reports.data.0.subject.detail', 'DIY space · Hartford, CT')
                ->where('reports.data.0.actions.0.value', 'hide_venue'));
    }
}
