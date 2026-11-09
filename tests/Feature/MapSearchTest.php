<?php

namespace Tests\Feature;

use App\DTO\MapPoint;
use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\User;
use App\Services\Geocoder;
use App\Support\MapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The map API: filters, radius, search, sorting, clustering, caching, the
 * card, and place lookup. Positions use real town centers (see
 * Database\Factories\Support\Towns) so distances are meaningful:
 *
 *   Hartford -> West Hartford  ~5.7 km
 *   Hartford -> New Haven      ~57 km
 *   Hartford -> Boston         ~148 km
 */
class MapSearchTest extends TestCase
{
    use RefreshDatabase;

    /** Covers Connecticut and eastern Massachusetts. */
    private const REGION = ['south' => 41.0, 'north' => 42.8, 'west' => -73.8, 'east' => -70.5];

    private const HARTFORD = ['lat' => 41.7658, 'lng' => -72.6734];

    private function map(array $params = [], ?User $user = null): TestResponse
    {
        $request = $user ? $this->actingAs($user) : $this;

        return $request->getJson(route('map.artists', $params));
    }

    /**
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column($response->json('data'), 'name');
    }

    // ---- Access -------------------------------------------------------

    public function test_guests_can_explore_the_map(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Open Door']);

        $this->map(self::REGION)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Open Door')
            ->assertJsonPath('meta.mode', 'markers');
    }

    public function test_the_map_api_is_rate_limited(): void
    {
        config(['riff.map.rate_limit_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->map(self::REGION)->assertOk();
        }

        $this->map(self::REGION)->assertTooManyRequests();
    }

    // ---- Filters ------------------------------------------------------

    public function test_genre_filter_matches_any_selected_genre(): void
    {
        Artist::factory()->inTown('Hartford')->withGenres(['Punk'])->create(['name' => 'Punk Act']);
        Artist::factory()->inTown('Hartford')->withGenres(['Folk'])->create(['name' => 'Folk Act']);
        Artist::factory()->inTown('Hartford')->withGenres(['Jazz'])->create(['name' => 'Jazz Act']);

        $this->assertEqualsCanonicalizing(
            ['Punk Act', 'Folk Act'],
            $this->names($this->map([...self::REGION, 'genres' => ['punk', 'folk']])),
        );
    }

    public function test_list_filters_accept_a_comma_separated_form(): void
    {
        Artist::factory()->inTown('Hartford')->withGenres(['Punk'])->create(['name' => 'Punk Act']);
        Artist::factory()->inTown('Hartford')->withGenres(['Folk'])->create(['name' => 'Folk Act']);
        Artist::factory()->inTown('Hartford')->withGenres(['Jazz'])->create(['name' => 'Jazz Act']);

        $this->assertEqualsCanonicalizing(
            ['Punk Act', 'Folk Act'],
            $this->names($this->map([...self::REGION, 'genres' => 'punk,folk'])),
        );
    }

    public function test_type_filter(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'A Band', 'artist_type' => 'band']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'A DJ', 'artist_type' => 'dj']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'A Rapper', 'artist_type' => 'rapper']);

        $this->assertEqualsCanonicalizing(
            ['A DJ', 'A Rapper'],
            $this->names($this->map([...self::REGION, 'types' => 'dj,rapper'])),
        );
    }

    public function test_has_music_filter(): void
    {
        Artist::factory()->inTown('Hartford')->withMusic()->create(['name' => 'Playable']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Silent']);

        $this->assertSame(['Playable'], $this->names($this->map([...self::REGION, 'has_music' => 1])));
    }

    public function test_has_photo_filter(): void
    {
        $pictured = Artist::factory()->inTown('Hartford')->create(['name' => 'Pictured']);
        $pictured->forceFill(['image_path' => 'artists/1/photo.jpg'])->save();
        Artist::factory()->inTown('Hartford')->create(['name' => 'Faceless']);

        $this->assertSame(['Pictured'], $this->names($this->map([...self::REGION, 'has_photo' => 1])));
    }

    public function test_filters_combine(): void
    {
        Artist::factory()->inTown('Hartford')->withGenres(['Punk'])->withMusic()->create(['name' => 'Match', 'artist_type' => 'band']);
        Artist::factory()->inTown('Hartford')->withGenres(['Punk'])->create(['name' => 'No Music', 'artist_type' => 'band']);
        Artist::factory()->inTown('Hartford')->withGenres(['Punk'])->withMusic()->create(['name' => 'Wrong Type', 'artist_type' => 'dj']);
        Artist::factory()->inTown('Hartford')->withGenres(['Folk'])->withMusic()->create(['name' => 'Wrong Genre', 'artist_type' => 'band']);

        $this->assertSame(['Match'], $this->names($this->map([
            ...self::REGION, 'genres' => 'punk', 'types' => 'band', 'has_music' => 1,
        ])));
    }

    public function test_unknown_genres_and_types_are_rejected(): void
    {
        $this->map([...self::REGION, 'genres' => 'not-a-genre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['genres.0']);

        $this->map([...self::REGION, 'types' => 'orchestra'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['types.0']);
    }

    public function test_followed_filter_shows_only_followed_artists(): void
    {
        $listener = User::factory()->create();
        $followed = Artist::factory()->inTown('Hartford')->create(['name' => 'Followed']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Stranger']);
        $listener->followedArtists()->attach($followed);

        $this->map([...self::REGION, 'followed' => 1], $listener)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['Followed'], $this->names($this->map([...self::REGION, 'followed' => 1], $listener)));
    }

    public function test_guests_cannot_use_the_followed_filter(): void
    {
        $this->map([...self::REGION, 'followed' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['followed']);
    }

    public function test_hidden_and_archived_artists_never_appear(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Visible']);
        Artist::factory()->inTown('Hartford')->hiddenFromMap()->create(['name' => 'Hidden']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Archived'])->delete();
        Artist::factory()->create(['name' => 'Ungeocoded', 'lat' => null, 'lng' => null]);

        $this->assertSame(['Visible'], $this->names($this->map(self::REGION)));
        $this->assertSame(['Visible'], $this->names($this->map(['q' => 'i'.'s'])));
        $this->map(self::REGION)->assertJsonPath('meta.total', 1);
    }

    // ---- Search -------------------------------------------------------

    public function test_search_matches_name_hometown_and_genre(): void
    {
        Artist::factory()->inTown('Hartford')->withGenres(['Jazz'])->create(['name' => 'Mossgrown']);
        Artist::factory()->inTown('New Haven')->withGenres(['Jazz'])->create(['name' => 'Elm City Five']);
        Artist::factory()->inTown('Boston')->withGenres(['Shoegaze'])->create(['name' => 'Static Bloom']);

        $this->assertSame(['Mossgrown'], $this->names($this->map(['q' => 'moss'])));
        $this->assertSame(['Elm City Five'], $this->names($this->map(['q' => 'new haven'])));
        $this->assertSame(['Static Bloom'], $this->names($this->map(['q' => 'shoegaze'])));
    }

    public function test_search_is_case_insensitive(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Mossgrown']);

        $this->assertSame(['Mossgrown'], $this->names($this->map(['q' => 'MOSS'])));
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => '100% Cotton']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Plain Name']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Snake_Case']);

        $this->assertSame(['100% Cotton'], $this->names($this->map(['q' => '0%'])));
        $this->assertSame(['Snake_Case'], $this->names($this->map(['q' => 'e_c'])));
    }

    public function test_search_ranks_name_prefix_matches_first(): void
    {
        // Hollow Moss is nearer to the origin, but The Moss starts with
        // the term.
        Artist::factory()->inTown('Hartford')->create(['name' => 'Hollow Moss']);
        Artist::factory()->inTown('Boston')->create(['name' => 'Moss Garden']);

        $response = $this->map(['q' => 'moss', ...self::HARTFORD]);

        $this->assertSame(['Moss Garden', 'Hollow Moss'], $this->names($response));
    }

    public function test_search_without_an_area_is_global_and_bounded(): void
    {
        config(['riff.map.search_limit' => 3]);
        Artist::factory()->count(5)->inTown('Boston')->create(['name' => 'Echo Echo']);

        $this->map(['q' => 'echo'])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.mode', 'search')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('clusters', null);
    }

    public function test_search_can_be_confined_to_the_viewport(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Echo Local']);
        // Kingston, NY sits just west of the region.
        Artist::factory()->inTown('Kingston')->create(['name' => 'Echo Far']);

        $this->assertSame(['Echo Local'], $this->names($this->map([...self::REGION, 'q' => 'echo'])));
    }

    public function test_a_one_character_search_is_rejected(): void
    {
        $this->map(['q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    // ---- Radius and distance -----------------------------------------

    public function test_radius_keeps_only_artists_within_it(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Here']);
        Artist::factory()->inTown('West Hartford')->create(['name' => 'Next Door']);
        Artist::factory()->inTown('New Haven')->create(['name' => 'Down The Road']);
        Artist::factory()->inTown('Boston')->create(['name' => 'Far Away']);

        $tenKm = $this->map([...self::HARTFORD, 'radius' => 10, 'unit' => 'km']);
        $this->assertSame(['Here', 'Next Door'], $this->names($tenKm));

        $hundredKm = $this->map([...self::HARTFORD, 'radius' => 100, 'unit' => 'km']);
        $this->assertSame(['Here', 'Next Door', 'Down The Road'], $this->names($hundredKm));
    }

    public function test_radius_is_read_in_the_requested_unit(): void
    {
        Artist::factory()->inTown('New Haven')->create(['name' => 'Down The Road']);

        // ~57 km is ~35 mi: inside 40 miles, outside 40 kilometres.
        $this->assertSame(['Down The Road'], $this->names($this->map([...self::HARTFORD, 'radius' => 40, 'unit' => 'mi'])));
        $this->assertSame([], $this->names($this->map([...self::HARTFORD, 'radius' => 40, 'unit' => 'km'])));
    }

    public function test_radius_requires_an_origin_and_respects_the_maximum(): void
    {
        $this->map(['radius' => 10])->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);

        config(['riff.map.max_radius_km' => 100]);

        $this->map([...self::HARTFORD, 'radius' => 150, 'unit' => 'km'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['radius']);
    }

    public function test_radius_and_viewport_intersect(): void
    {
        Artist::factory()->inTown('Hartford')->create(['name' => 'Here']);

        // A viewport over Boston and a radius around Hartford share no
        // ground at all.
        $boston = ['south' => 42.2, 'north' => 42.5, 'west' => -71.3, 'east' => -70.9];

        $this->map([...$boston, ...self::HARTFORD, 'radius' => 20, 'unit' => 'km'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_results_are_nearest_first_with_distances_in_the_unit(): void
    {
        Artist::factory()->inTown('Boston')->create(['name' => 'Far Away']);
        Artist::factory()->inTown('Hartford')->create(['name' => 'Here']);
        Artist::factory()->inTown('New Haven')->create(['name' => 'Down The Road']);

        $km = $this->map([...self::REGION, ...self::HARTFORD, 'unit' => 'km']);

        $this->assertSame(['Here', 'Down The Road', 'Far Away'], $this->names($km));
        $this->assertSame(0.0, (float) $km->json('data.0.distance'));
        $this->assertEqualsWithDelta(57.0, $km->json('data.1.distance'), 2.0);
        $this->assertEqualsWithDelta(148.0, $km->json('data.2.distance'), 3.0);
        $km->assertJsonPath('meta.unit', 'km');

        $mi = $this->map([...self::REGION, ...self::HARTFORD, 'unit' => 'mi']);
        $this->assertEqualsWithDelta(35.4, $mi->json('data.1.distance'), 1.5);
    }

    public function test_distance_unit_defaults_to_the_viewers_preference(): void
    {
        Artist::factory()->inTown('Hartford')->create();

        $this->map(self::REGION, User::factory()->create(['distance_unit' => 'km']))
            ->assertJsonPath('meta.unit', 'km');
    }

    public function test_alternative_sorts(): void
    {
        $this->travelTo(now()->subDay());
        Artist::factory()->inTown('Boston')->create(['name' => 'Alpha']);
        $this->travelBack();
        Artist::factory()->inTown('Hartford')->create(['name' => 'Charlie']);
        Artist::factory()->inTown('New Haven')->create(['name' => 'Bravo']);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names($this->map([...self::REGION, 'sort' => 'name'])));
        $this->assertSame('Alpha', $this->names($this->map([...self::REGION, 'sort' => 'newest']))[2]);
    }

    public function test_origin_precision_is_reduced(): void
    {
        Artist::factory()->inTown('Hartford')->create();

        // A browser's precise geolocation is never echoed back or used at
        // full precision.
        $this->map([...self::REGION, 'lat' => 41.76581234, 'lng' => -72.67345678])
            ->assertJsonPath('meta.origin', ['lat' => 41.766, 'lng' => -72.673]);
    }

    // ---- Clustering ---------------------------------------------------

    public function test_dense_areas_return_clusters_and_the_nearest_artists(): void
    {
        config(['riff.map.max_markers' => 5, 'riff.map.list_limit' => 3]);

        Artist::factory()->count(4)->inTown('Hartford')->create();
        Artist::factory()->count(3)->inTown('Boston')->create();
        Artist::factory()->count(2)->inTown('New Haven')->create();

        $response = $this->map([...self::REGION, ...self::HARTFORD])
            ->assertOk()
            ->assertJsonPath('meta.mode', 'clusters')
            ->assertJsonPath('meta.total', 9)
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonCount(3, 'data');

        // The list gets the nearest artists...
        foreach ($response->json('data') as $marker) {
            $this->assertSame('Hartford, CT', $marker['location']);
        }

        // ...and the clusters account for every match, one per town here.
        $clusters = collect($response->json('clusters'));
        $this->assertSame(9, $clusters->sum('count'));
        $this->assertCount(3, $clusters);
        $this->assertSame(4, $clusters->firstWhere('lat', 41.7658)['count']);
    }

    public function test_the_nearest_list_widens_its_search_when_the_origin_is_sparse(): void
    {
        config(['riff.map.max_markers' => 3, 'riff.map.list_limit' => 3]);

        // One artist near the origin, the rest ~150 km away: the first,
        // small search circle cannot fill the list and must grow.
        Artist::factory()->inTown('Hartford')->create(['name' => 'Near']);
        Artist::factory()->count(4)->inTown('Boston')->create(['name' => 'Far']);

        $response = $this->map([...self::REGION, ...self::HARTFORD])
            ->assertJsonPath('meta.mode', 'clusters')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonCount(3, 'data');

        $this->assertSame(['Near', 'Far', 'Far'], $this->names($response));
    }

    public function test_the_nearest_list_works_when_the_origin_is_outside_the_viewport(): void
    {
        config(['riff.map.max_markers' => 2, 'riff.map.list_limit' => 2]);

        Artist::factory()->inTown('Providence')->create(['name' => 'Providence']);
        Artist::factory()->inTown('Worcester')->create(['name' => 'Worcester']);
        Artist::factory()->inTown('Boston')->create(['name' => 'Boston']);

        // Viewport over eastern MA/RI; distances measured from Albany, far
        // to the west and outside it.
        $east = ['south' => 41.5, 'north' => 42.6, 'west' => -72.0, 'east' => -70.8];

        $response = $this->map([...$east, 'lat' => 42.6526, 'lng' => -73.7562])
            ->assertJsonPath('meta.mode', 'clusters')
            ->assertJsonCount(2, 'data');

        // From Albany: Worcester ~166 km, Providence ~214 km, Boston ~224 km.
        $this->assertSame(['Worcester', 'Providence'], $this->names($response));
    }

    public function test_clusters_honour_filters(): void
    {
        config(['riff.map.max_markers' => 2]);

        Artist::factory()->count(3)->inTown('Hartford')->withGenres(['Punk'])->create();
        Artist::factory()->count(5)->inTown('Hartford')->withGenres(['Jazz'])->create();

        $response = $this->map([...self::REGION, 'genres' => 'punk'])->assertJsonPath('meta.mode', 'clusters');

        $this->assertSame(3, collect($response->json('clusters'))->sum('count'));
    }

    public function test_cluster_bounds_cover_their_artists(): void
    {
        config(['riff.map.max_markers' => 1, 'riff.map.cluster_grid' => 1]);

        Artist::factory()->inTown('Hartford')->create();
        Artist::factory()->inTown('Boston')->create();

        $bounds = $this->map(self::REGION)->json('clusters.0.bounds');

        $this->assertEqualsWithDelta(41.7658, $bounds['south'], 0.0001);
        $this->assertEqualsWithDelta(42.3601, $bounds['north'], 0.0001);
        $this->assertEqualsWithDelta(-72.6734, $bounds['west'], 0.0001);
        $this->assertEqualsWithDelta(-71.0589, $bounds['east'], 0.0001);
    }

    public function test_a_client_limit_lowers_the_cap(): void
    {
        Artist::factory()->count(4)->inTown('Hartford')->create();

        $this->map([...self::REGION, 'limit' => 2])
            ->assertJsonPath('meta.mode', 'clusters')
            ->assertJsonCount(2, 'data');

        $this->map([...self::REGION, 'limit' => config('riff.map.max_markers') + 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
    }

    // ---- Caching ------------------------------------------------------

    private function countArtistQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"artists"'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_repeated_viewports_are_served_from_cache(): void
    {
        Artist::factory()->inTown('Hartford')->create();

        $this->assertGreaterThan(0, $this->countArtistQueries(fn () => $this->map(self::REGION)->assertOk()));
        $this->assertSame(0, $this->countArtistQueries(fn () => $this->map(self::REGION)->assertOk()));
    }

    public function test_a_small_pan_reuses_the_cached_result(): void
    {
        Artist::factory()->inTown('Hartford')->create();

        $view = ['south' => 41.52, 'north' => 41.98, 'west' => -72.95, 'east' => -72.41];
        $nudged = ['south' => 41.53, 'north' => 41.99, 'west' => -72.94, 'east' => -72.40];

        $this->map($view)->assertOk();

        $this->assertSame(0, $this->countArtistQueries(fn () => $this->map($nudged)->assertOk()));
    }

    public function test_editing_an_artist_invalidates_cached_results(): void
    {
        $artist = Artist::factory()->inTown('Hartford')->create(['name' => 'Before']);

        $this->assertSame(['Before'], $this->names($this->map(self::REGION)));

        $artist->update(['name' => 'After']);

        $this->assertSame(['After'], $this->names($this->map(self::REGION)));
    }

    public function test_hiding_an_artist_invalidates_cached_results(): void
    {
        $artist = Artist::factory()->inTown('Hartford')->create();
        $this->map(self::REGION)->assertJsonCount(1, 'data');

        $artist->update(['show_on_map' => false]);

        $this->map(self::REGION)->assertJsonCount(0, 'data');
    }

    public function test_a_genre_only_edit_invalidates_cached_results(): void
    {
        $artist = Artist::factory()->inTown('Hartford')->withGenres(['Jazz'])->create();
        $member = User::factory()->create();
        $artist->members()->attach($member);

        $this->map([...self::REGION, 'genres' => 'punk'])->assertJsonCount(0, 'data');

        $this->actingAs($member)->patch(route('artists.update', $artist), [
            'name' => $artist->name,
            'artist_type' => $artist->artist_type,
            'genres' => [Genre::where('slug', 'punk')->value('id')],
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
            'show_on_map' => true,
        ]);

        $this->map([...self::REGION, 'genres' => 'punk'])->assertJsonCount(1, 'data');
    }

    public function test_geocoding_invalidates_cached_results(): void
    {
        // Geocoding writes coordinates with saveQuietly, bypassing the
        // model events that normally rotate the cache version.
        $artist = Artist::factory()->create(['lat' => null, 'lng' => null]);
        $before = MapCache::version();

        $geocoder = $this->mock(Geocoder::class);
        $geocoder->shouldReceive('fromAddress')->andReturn(new MapPoint(41.7658, -72.6734));

        (new GeocodeAddress($artist))->handle($geocoder);

        $this->assertNotSame($before, MapCache::version());
    }

    public function test_cached_results_still_report_distance_from_the_requested_origin(): void
    {
        Artist::factory()->inTown('New Haven')->create();

        $fromHartford = $this->map([...self::REGION, ...self::HARTFORD, 'unit' => 'km'])->json('data.0.distance');
        $fromNewHaven = $this->map([...self::REGION, 'lat' => 41.3083, 'lng' => -72.9279, 'unit' => 'km'])->json('data.0.distance');

        $this->assertGreaterThan(50, $fromHartford);
        $this->assertSame(0.0, (float) $fromNewHaven);
    }

    // ---- Card ---------------------------------------------------------

    public function test_the_card_carries_music_follow_state_and_distance(): void
    {
        $artist = Artist::factory()->inTown('New Haven')->withMusic()->create([
            'biography' => str_repeat('A long story. ', 50),
        ]);
        $listener = User::factory()->create();
        $listener->followedArtists()->attach($artist);

        $response = $this->actingAs($listener)
            ->getJson(route('map.artists.card', ['artist' => $artist, ...self::HARTFORD, 'unit' => 'km']))
            ->assertOk()
            ->assertJsonPath('data.isFollowing', true)
            ->assertJsonPath('data.followersCount', 1)
            ->assertJsonPath('data.url', route('artists.show', $artist));

        $this->assertNotNull($response->json('data.music.embed_url'));
        $this->assertLessThanOrEqual(280, mb_strlen($response->json('data.bio')));
        $this->assertEqualsWithDelta(57.0, $response->json('data.distance'), 2.0);
    }

    public function test_guests_get_a_card_without_follow_state(): void
    {
        $artist = Artist::factory()->inTown('Hartford')->create();

        $this->getJson(route('map.artists.card', $artist))
            ->assertOk()
            ->assertJsonPath('data.isFollowing', null)
            ->assertJsonPath('data.distance', null);
    }

    public function test_hidden_and_archived_artists_have_no_card(): void
    {
        $hidden = Artist::factory()->inTown('Hartford')->hiddenFromMap()->create();
        $archived = Artist::factory()->inTown('Hartford')->create();
        $archived->delete();

        $this->getJson(route('map.artists.card', $hidden))->assertNotFound();
        $this->getJson(route('map.artists.card', $archived))->assertNotFound();
    }

    // ---- Places -------------------------------------------------------

    public function test_places_finds_towns_with_artists_by_prefix(): void
    {
        Artist::factory()->count(3)->inTown('Hartford')->create();
        Artist::factory()->inTown('West Hartford')->create();
        Artist::factory()->inTown('Boston')->create();

        $places = $this->getJson(route('map.places', ['q' => 'hart']))->assertOk()->json('data');

        $this->assertCount(1, $places);
        $this->assertSame('Hartford', $places[0]['name']);
        $this->assertSame('CT', $places[0]['region']);
        $this->assertSame(3, $places[0]['artistCount']);
        $this->assertEqualsWithDelta(41.7658, $places[0]['lat'], 0.0001);
    }

    public function test_places_never_reveals_a_town_through_a_hidden_artist(): void
    {
        Artist::factory()->inTown('Mystic')->hiddenFromMap()->create();

        $this->getJson(route('map.places', ['q' => 'myst']))->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Page props ---------------------------------------------------

    public function test_the_map_page_offers_filter_options_matching_validation(): void
    {
        $user = User::factory()->create(['distance_unit' => 'km']);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filterOptions.unit', 'km')
                ->has('filterOptions.genres', count(config('riff.genres')))
                ->has('filterOptions.types', count(config('riff.artist.types')))
                ->where('filterOptions.radiusOptions', config('riff.map.radius_options'))
            );
    }
}
