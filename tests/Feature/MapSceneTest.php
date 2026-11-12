<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use App\Support\ShowWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\GenreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The scene map's show and venue pins: /map/shows and /map/venues.
 */
class MapSceneTest extends TestCase
{
    use RefreshDatabase;

    /** Around Hartford, wide enough for the factory towns used here. */
    private const AREA = ['south' => 41.5, 'west' => -73.0, 'north' => 42.0, 'east' => -72.4];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenreSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function shows(array $extra = [], ?User $as = null): TestResponse
    {
        $request = $as ? $this->actingAs($as) : $this;

        return $request->getJson(route('map.shows', [...self::AREA, 'tz' => 'America/New_York', ...$extra]));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function venues(array $extra = []): TestResponse
    {
        return $this->getJson(route('map.venues', [...self::AREA, ...$extra]));
    }

    // ---- Shows ------------------------------------------------------------------

    public function test_a_venue_show_is_pinned_at_its_venue(): void
    {
        $venue = Venue::factory()->at('Hartford')->create(['name' => 'The Lantern Room']);
        $show = Show::factory()->create(['venue_id' => $venue->id]);

        $this->shows()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $show->id)
            ->assertJsonPath('data.0.kind', 'show')
            ->assertJsonPath('data.0.lat', $venue->lat)
            ->assertJsonPath('data.0.place', 'The Lantern Room');
    }

    public function test_a_one_off_show_is_pinned_at_its_own_town(): void
    {
        Show::factory()->oneOff('Hartford')->create();

        $this->shows()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.isOneOff', true)
            ->assertJsonPath('data.0.place', 'Backyard on the west side')
            ->assertJsonPath('data.0.locationNote', 'Ask a punk');
    }

    public function test_cancelled_past_and_out_of_area_shows_are_not_pinned(): void
    {
        Show::factory()->cancelled()->create(['venue_id' => Venue::factory()->at('Hartford')]);
        Show::factory()->past()->create(['venue_id' => Venue::factory()->at('Hartford')]);
        Show::factory()->create(['venue_id' => Venue::factory()->at('Providence')]);

        $this->shows()->assertJsonCount(0, 'data');
    }

    public function test_a_show_at_a_taken_down_venue_leaves_the_map(): void
    {
        $venue = Venue::factory()->at('Hartford')->create();
        Show::factory()->create(['venue_id' => $venue->id]);
        $venue->delete();

        $this->shows()->assertJsonCount(0, 'data');
    }

    public function test_the_date_window_narrows_shows(): void
    {
        // Wednesday evening in Hartford.
        $this->travelTo(CarbonImmutable::parse('2027-03-03 18:00', 'America/New_York'));
        $venue = Venue::factory()->at('Hartford')->create();
        $at = fn (string $local) => Show::factory()->create([
            'venue_id' => $venue->id,
            'starts_at' => CarbonImmutable::parse($local, 'America/New_York')->utc(),
        ]);

        $tonight = $at('2027-03-03 21:00');
        $saturday = $at('2027-03-06 20:00');
        $nextWeek = $at('2027-03-09 20:00');
        $nextMonth = $at('2027-03-25 20:00');

        $ids = fn (string $when) => collect($this->shows(['when' => $when])->json('data'))->pluck('id')->all();

        $this->assertSame([$tonight->id], $ids('tonight'));
        // Before Friday, "this weekend" starts on Friday.
        $this->assertSame([$saturday->id], $ids('weekend'));
        $this->assertSame([$tonight->id, $saturday->id, $nextWeek->id], $ids('week'));
        $this->assertSame([$tonight->id, $saturday->id, $nextWeek->id, $nextMonth->id], $ids('month'));
        // Default is the month.
        $this->assertCount(4, $this->shows()->json('data'));
    }

    public function test_the_weekend_window_on_a_saturday_starts_now(): void
    {
        $window = ShowWindow::make('weekend', 'America/New_York', CarbonImmutable::parse('2027-03-06 12:00', 'America/New_York'));

        $this->assertSame('2027-03-06 08:00', $window->from->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame('2027-03-07 23:59', $window->to->setTimezone('America/New_York')->format('Y-m-d H:i'));
    }

    public function test_the_genre_filter_matches_confirmed_acts_only(): void
    {
        $punk = Genre::where('name', 'Punk')->firstOrFail();
        $folk = Genre::where('name', 'Folk')->firstOrFail();
        $punkBand = Artist::factory()->create();
        $punkBand->genres()->sync([$punk->id]);
        $folkBand = Artist::factory()->create();
        $folkBand->genres()->sync([$folk->id]);

        $punkShow = Show::factory()->create(['host_artist_id' => $punkBand->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        $folkShow = Show::factory()->create(['host_artist_id' => $folkBand->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        // A punk band merely *asked* onto the folk bill doesn't make it punk.
        $folkShow->lineup()->make(['artist_id' => $punkBand->id, 'position' => 1])
            ->forceFill(['status' => LineupEntry::PENDING])->save();

        $this->shows(['genres' => 'punk'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $punkShow->id);
    }

    public function test_pending_acts_are_never_named_on_a_pin(): void
    {
        $show = Show::factory()->create(['venue_id' => Venue::factory()->at('Hartford')]);
        $asked = Artist::factory()->create(['name' => 'Not Yet Agreed']);
        $show->lineup()->make(['artist_id' => $asked->id, 'position' => 1])
            ->forceFill(['status' => LineupEntry::PENDING])->save();

        $this->assertStringNotContainsString('Not Yet Agreed', (string) $this->shows()->json('data.0.title'));
    }

    public function test_a_show_hides_only_when_every_riff_act_on_it_is_blocked(): void
    {
        $viewer = User::factory()->create();
        $blocked = Artist::factory()->create();
        $other = Artist::factory()->create();
        $viewer->blockedArtists()->attach($blocked);

        $solo = Show::factory()->create(['host_artist_id' => $blocked->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        $shared = Show::factory()->create(['host_artist_id' => $blocked->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        $shared->lineup()->make(['artist_id' => $other->id, 'position' => 1])
            ->forceFill(['status' => LineupEntry::CONFIRMED])->save();
        // A free-text act is not a Riff artist the viewer could have
        // blocked, so it doesn't keep the show visible.
        $withName = Show::factory()->create(['host_artist_id' => $blocked->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        $withName->lineup()->create(['name' => 'Unlisted Openers', 'position' => 1]);

        $ids = collect($this->shows([], $viewer)->assertHeader('Cache-Control', 'no-store, private')->json('data'))->pluck('id');

        $this->assertEquals([$shared->id], $ids->all());
        // Everyone else still sees all three. (A different viewer:
        // actingAs persists for the rest of a test.)
        $this->assertCount(3, $this->shows([], User::factory()->create())->json('data'));
        $this->assertNotContains($solo->id, $ids);
    }

    public function test_a_new_show_appears_despite_the_cache(): void
    {
        $venue = Venue::factory()->at('Hartford')->create();
        $this->shows()->assertJsonCount(0, 'data');

        Show::factory()->create(['venue_id' => $venue->id]);

        $this->shows()->assertJsonCount(1, 'data');
    }

    public function test_confirming_a_slot_refreshes_the_cached_title(): void
    {
        $host = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $guest = Artist::factory()->create(['name' => 'Sewer Psalms']);
        $show = Show::factory()->create(['host_artist_id' => $host->id, 'venue_id' => Venue::factory()->at('Hartford')]);
        $entry = $show->lineup()->make(['artist_id' => $guest->id, 'position' => 1]);
        $entry->forceFill(['status' => LineupEntry::PENDING])->save();

        $this->shows()->assertJsonPath('data.0.title', 'Gutter Hymns');

        $entry->forceFill(['status' => LineupEntry::CONFIRMED])->save();

        $this->shows()->assertJsonPath('data.0.title', 'Gutter Hymns & Sewer Psalms');
    }

    public function test_an_area_is_required(): void
    {
        $this->getJson(route('map.shows'))->assertUnprocessable()->assertJsonValidationErrors('south');
        $this->getJson(route('map.venues'))->assertUnprocessable()->assertJsonValidationErrors('south');
        $this->getJson(route('map.shows', [...self::AREA, 'when' => 'someday']))->assertJsonValidationErrors('when');
    }

    // ---- Venues -----------------------------------------------------------------

    public function test_active_and_new_venues_are_pinned_and_dormant_ones_fade(): void
    {
        $busy = Venue::factory()->at('Hartford')->create(['name' => 'Busy']);
        Show::factory()->create(['venue_id' => $busy->id]);

        $new = Venue::factory()->at('Hartford')->create(['name' => 'Brand New']);

        $dormant = Venue::factory()->at('Hartford')->create(['name' => 'Dormant']);
        $dormant->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        $recentlyQuiet = Venue::factory()->at('Hartford')->create(['name' => 'Played Last Month']);
        $recentlyQuiet->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        Show::factory()->create(['venue_id' => $recentlyQuiet->id, 'starts_at' => now()->subMonth()]);

        $names = collect($this->venues()->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Brand New', 'Busy', 'Played Last Month'], $names);
        // The page outlives the pin.
        $this->get(route('venues.show', $dormant))->assertOk();
    }

    public function test_hidden_venues_are_not_pinned(): void
    {
        $venue = Venue::factory()->at('Hartford')->create();
        $venue->forceFill(['show_on_map' => false])->save();

        $this->venues()->assertJsonCount(0, 'data');
    }

    public function test_a_venue_pin_counts_upcoming_shows_and_hides_a_private_street(): void
    {
        $venue = Venue::factory()->diy()->at('Hartford')->create(['name' => 'Basement Signal']);
        Show::factory()->count(2)->create(['venue_id' => $venue->id]);
        Show::factory()->cancelled()->create(['venue_id' => $venue->id]);

        $pin = $this->venues()->json('data.0');

        $this->assertSame(2, $pin['upcomingCount']);
        $this->assertTrue($pin['isPrivate']);
        $this->assertSame('Hartford, CT', $pin['area']);
        $this->assertArrayNotHasKey('street_address', $pin);
        $this->assertArrayNotHasKey('address', $pin);
    }

    public function test_a_genre_filter_keeps_venues_with_a_matching_bill_coming_up(): void
    {
        $punk = Genre::where('name', 'Punk')->firstOrFail();
        $band = Artist::factory()->create();
        $band->genres()->sync([$punk->id]);

        $punkRoom = Venue::factory()->at('Hartford')->create(['name' => 'Punk Room']);
        Show::factory()->create(['host_artist_id' => $band->id, 'venue_id' => $punkRoom->id]);
        $jazzRoom = Venue::factory()->at('Hartford')->create(['name' => 'Jazz Room']);
        Show::factory()->create(['venue_id' => $jazzRoom->id]);

        $this->venues(['genres' => 'punk'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Punk Room');
    }

    public function test_the_map_shell_offers_the_kinds_and_windows(): void
    {
        $this->get(route('map.index'))
            ->assertInertia(fn ($page) => $page
                ->has('filterOptions.kinds', 4)
                ->where('filterOptions.windows.0.value', 'tonight'));
    }
}
