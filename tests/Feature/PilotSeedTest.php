<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\PilotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pilot scene is what Riff is demonstrated with, so it is worth knowing
 * that it still seeds, still lands on the map, and can be run twice.
 */
class PilotSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Generated cover art would otherwise be written to the real media
        // disk every time this runs.
        Storage::fake(config('riff.media.disk'));
    }

    public function test_the_pilot_area_seeds_a_scene_on_the_map(): void
    {
        $this->seed(PilotSeeder::class);

        $onMap = Artist::query()->visibleOnMap()->geolocated()->get();

        // Dense enough to be worth looking at, and all of it in one area.
        $this->assertGreaterThanOrEqual(20, $onMap->count());
        $this->assertTrue($onMap->every(fn (Artist $artist) => $artist->stateOrProvince === 'CT'));

        // Several acts per town is the point: that is what the map's
        // same-town clustering exists for.
        $this->assertGreaterThanOrEqual(3, $onMap->where('hometown', 'Hartford')->count());

        // A profile is only worth stopping on if it has something on it.
        $this->assertTrue($onMap->every(fn (Artist $artist) => filled($artist->biography)));
        $this->assertGreaterThanOrEqual(12, $onMap->whereNotNull('music_provider')->count());
        $this->assertGreaterThanOrEqual(20, $onMap->whereNotNull('image_path')->count());
    }

    public function test_every_seeded_genre_is_one_the_filters_offer(): void
    {
        $this->seed(PilotSeeder::class);

        $curated = Genre::where('is_curated', true)->pluck('id');

        foreach (Artist::with('genres')->get() as $artist) {
            $this->assertNotEmpty($artist->genres, "{$artist->name} has no genres.");
            $this->assertEmpty(
                $artist->genres->pluck('id')->diff($curated),
                "{$artist->name} carries a genre the filters do not offer.",
            );
        }
    }

    public function test_the_scene_covers_the_states_a_profile_can_be_in(): void
    {
        $this->seed(PilotSeeder::class);

        $this->assertSame(2, Artist::where('show_on_map', false)->count());
        $this->assertSame(1, Artist::onlyTrashed()->count());
        $this->assertGreaterThanOrEqual(1, Artist::whereNotNull('music_provider')->count());
    }

    public function test_the_scene_has_listeners_following_it(): void
    {
        $this->seed(PilotSeeder::class);

        $followed = Artist::query()->withCount('followers')->get()->where('followers_count', '>', 0);

        $this->assertGreaterThanOrEqual(10, $followed->count());
        $this->assertGreaterThan(0, User::query()->whereNotNull('onboarding_intent')->count());
    }

    public function test_the_report_queue_is_not_empty(): void
    {
        $this->seed(PilotSeeder::class);

        $this->assertGreaterThanOrEqual(3, Report::query()->open()->count());
    }

    public function test_seeding_twice_does_not_duplicate_the_scene(): void
    {
        $this->seed(PilotSeeder::class);

        $artists = Artist::withTrashed()->count();
        $reports = Report::count();
        $users = User::count();

        $this->seed(PilotSeeder::class);

        $this->assertSame($artists, Artist::withTrashed()->count());
        $this->assertSame($reports, Report::count());
        $this->assertSame($users, User::count());
    }

    public function test_the_map_serves_the_pilot_area_to_a_guest(): void
    {
        $this->seed(PilotSeeder::class);

        // The viewport a visitor gets when the map opens on its default
        // center, roughly Greater Hartford.
        $this->getJson(route('map.artists', [
            'south' => 41.5,
            'north' => 42.0,
            'west' => -72.9,
            'east' => -72.3,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.mode', 'markers')
            ->assertJsonStructure(['data' => [['id', 'name', 'lat', 'lng', 'genres', 'hasMusic']]]);

        $this->assertGreaterThanOrEqual(10, Artist::query()
            ->visibleOnMap()
            ->geolocated()
            ->withinBounds(41.5, -72.9, 42.0, -72.3)
            ->count());
    }
}
