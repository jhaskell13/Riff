<?php

namespace Tests\Feature;

use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Place;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use App\Services\Geocoder;
use Database\Seeders\GenreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Scene views: /scenes/{place}/{family?} — a place plus a genre family.
 */
class SceneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenreSeeder::class);
    }

    /** @param list<string> $genres */
    private function artist(string $name, string $town, array $genres): Artist
    {
        $artist = Artist::factory()->inTown($town)->create(['name' => $name]);
        $artist->genres()->sync(Genre::whereIn('name', $genres)->pluck('id'));

        return $artist;
    }

    private function hartford(): Place
    {
        $this->artisan('riff:sync-places')->assertSuccessful();

        return Place::where('slug', 'hartford-ct')->firstOrFail();
    }

    // ---- Families and places -----------------------------------------------------

    public function test_every_curated_genre_belongs_to_a_family(): void
    {
        $inFamilies = collect(config('riff.scenes.families'))->pluck('genres')->flatten()->unique();

        $this->assertSame([], array_values(array_diff(config('riff.genres'), $inFamilies->all())));
    }

    public function test_places_are_synced_from_town_level_records_only(): void
    {
        $host = Artist::factory()->inTown('Hartford')->create();
        Artist::factory()->inTown('Hartford')->create();
        Venue::factory()->diy()->at('Middletown')->create();
        // A public venue's point is its street, not its town: skipped.
        Venue::factory()->at('Bristol')->create();
        // Explicit host: the factory's own would land in a random town.
        Show::factory()->oneOff('Glastonbury')->create(['host_artist_id' => $host->id]);

        $this->artisan('riff:sync-places')->assertSuccessful();
        $this->artisan('riff:sync-places')->assertSuccessful();

        $slugs = Place::orderBy('slug')->pluck('slug')->all();

        $this->assertSame(['glastonbury-ct', 'hartford-ct', 'middletown-ct'], $slugs);
        $this->assertEqualsWithDelta(41.7658, Place::where('slug', 'hartford-ct')->value('lat'), 0.0001);
    }

    public function test_geocoding_an_artist_records_its_town(): void
    {
        config(['services.google.maps.key' => 'test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => 41.55, 'lng' => -72.65]]]],
        ])]);

        $artist = Artist::factory()->create(['hometown' => 'Middletown', 'stateOrProvince' => 'CT', 'lat' => null, 'lng' => null]);
        (new GeocodeAddress($artist))->handle(app(Geocoder::class));

        $place = Place::where('slug', 'middletown-ct')->firstOrFail();
        $this->assertSame('Middletown', $place->name);
        $this->assertSame(41.55, $place->lat);
    }

    // ---- The page ----------------------------------------------------------------

    public function test_a_guest_sees_a_scene(): void
    {
        $this->artist('The Silver Mill', 'Hartford', ['Punk']);
        $place = $this->hartford();

        $this->get(route('scenes.show', $place))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Scenes/Show')
                ->where('scene.place.label', 'Hartford, CT')
                ->where('scene.family', null)
                ->where('scene.artistTotal', 1)
                ->where('canFollow', false));
    }

    public function test_a_family_narrows_artists_shows_and_venues(): void
    {
        $punk = $this->artist('The Silver Mill', 'Hartford', ['Hardcore']);
        $folk = $this->artist('Northern Avenue', 'West Hartford', ['Folk']);
        $punkRoom = Venue::factory()->at('Hartford')->create(['name' => 'Punk Room']);
        $folkRoom = Venue::factory()->at('Hartford')->create(['name' => 'Folk Room']);
        $punkShow = Show::factory()->create(['host_artist_id' => $punk->id, 'venue_id' => $punkRoom->id]);
        Show::factory()->create(['host_artist_id' => $folk->id, 'venue_id' => $folkRoom->id]);
        $place = $this->hartford();

        $this->get(route('scenes.show', [$place, 'punk']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scene.family.label', 'Punk')
                ->where('scene.artistTotal', 1)
                ->where('scene.artists.0.name', 'The Silver Mill')
                ->has('scene.shows', 1)
                ->where('scene.shows.0.id', $punkShow->id)
                ->has('scene.venues', 1)
                ->where('scene.venues.0.name', 'Punk Room')
                // Punk ⊃ Hardcore: the map link carries the whole family.
                ->where('scene.mapUrl', fn (string $url) => str_contains(urldecode($url), 'genres=punk,hardcore,emo,ska')));
    }

    public function test_the_scene_is_a_radius_not_a_town_line(): void
    {
        $this->artist('Next Door', 'West Hartford', ['Punk']);
        $this->artist('Far Away', 'Boston', ['Punk']);
        // Something in Hartford itself, so the town has a scene page.
        $this->artist('Folkie', 'Hartford', ['Folk']);
        $place = $this->hartford();

        $this->get(route('scenes.show', [$place, 'punk']))
            ->assertInertia(fn ($page) => $page
                ->where('scene.artistTotal', 1)
                ->where('scene.artists.0.name', 'Next Door'));
    }

    public function test_the_families_here_are_counted_once_per_artist(): void
    {
        // Two genres in one family: one punk artist, not two.
        $this->artist('Both', 'Hartford', ['Punk', 'Hardcore']);
        $this->artist('Folkie', 'Hartford', ['Folk']);
        $place = $this->hartford();

        $this->get(route('scenes.show', $place))
            ->assertInertia(fn ($page) => $page
                ->has('scene.families', 2)
                ->where('scene.families', fn ($families) => collect($families)->pluck('count', 'slug')->all() === ['punk' => 1, 'folk' => 1]));
    }

    public function test_unknown_places_and_families_are_404s(): void
    {
        $this->artist('Here', 'Hartford', ['Punk']);
        $place = $this->hartford();

        $this->get('/scenes/atlantis-xx')->assertNotFound();
        $this->get("/scenes/{$place->slug}/polka")->assertNotFound();
    }

    public function test_an_empty_scene_invites_artists_in(): void
    {
        $this->artist('Folkie', 'Hartford', ['Folk']);
        $place = $this->hartford();

        $this->get(route('scenes.show', [$place, 'electronic']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scene.artistTotal', 0)
                ->has('scene.shows', 0));
    }

    public function test_a_blocked_artist_leaves_the_blockers_scene_only(): void
    {
        $blocked = $this->artist('Blocked Band', 'Hartford', ['Punk']);
        $this->artist('Other Band', 'Hartford', ['Punk']);
        $viewer = User::factory()->create();
        $viewer->blockedArtists()->attach($blocked);
        $place = $this->hartford();

        $names = fn ($response) => collect($response->viewData('page')['props']['scene']['artists'])->pluck('name')->all();

        $this->assertSame(['Other Band'], $names($this->actingAs($viewer)->get(route('scenes.show', [$place, 'punk']))));
        $this->assertCount(2, $names($this->actingAs(User::factory()->create())->get(route('scenes.show', [$place, 'punk']))));
    }

    public function test_a_new_show_appears_despite_the_cache(): void
    {
        $band = $this->artist('The Silver Mill', 'Hartford', ['Punk']);
        $place = $this->hartford();

        $this->get(route('scenes.show', $place))->assertInertia(fn ($page) => $page->has('scene.shows', 0));

        Show::factory()->create(['host_artist_id' => $band->id, 'venue_id' => Venue::factory()->at('Hartford')]);

        $this->get(route('scenes.show', $place))->assertInertia(fn ($page) => $page->has('scene.shows', 1));
    }

    // ---- Ways in -----------------------------------------------------------------

    public function test_artist_and_venue_pages_link_into_their_scene(): void
    {
        $artist = $this->artist('The Silver Mill', 'Hartford', ['Punk', 'Folk']);
        $venue = Venue::factory()->at('Hartford')->create();
        $this->hartford();

        $this->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page->where('sceneLinks', fn ($links) => collect($links)->pluck('label')->all() === [
                'Hartford punk', 'Hartford folk & roots', 'Everything in Hartford',
            ]));

        $this->get(route('venues.show', $venue))
            ->assertInertia(fn ($page) => $page->where('sceneLinks.0.label', 'Everything in Hartford'));
    }

    public function test_the_maps_town_suggestions_link_to_their_scene(): void
    {
        $this->artist('Here', 'Hartford', ['Punk']);
        $this->artist('There', 'Bristol', ['Folk']);
        $this->artisan('riff:sync-places');
        Place::where('slug', 'bristol-ct')->delete();

        $places = collect($this->getJson(route('map.places', ['q' => 'har']))->json('data'))->keyBy('name');
        $this->assertSame(route('scenes.show', 'hartford-ct'), $places['Hartford']['sceneUrl']);

        $this->assertNull($this->getJson(route('map.places', ['q' => 'bri']))->json('data.0.sceneUrl'));
    }

    public function test_a_town_without_a_scene_page_gets_no_links(): void
    {
        $artist = $this->artist('Lonely', 'Hartford', ['Punk']);

        // No sync has run, so no place exists yet.
        $this->get(route('artists.show', $artist))->assertInertia(fn ($page) => $page->where('sceneLinks', []));
        $this->assertSame('hartford-ct', Place::slugFor('Hartford', 'CT'));
        $this->assertSame(Str::slug('Hartford CT'), Place::slugFor('Hartford', 'CT'));
    }
}
