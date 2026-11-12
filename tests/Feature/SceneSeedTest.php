<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Venue;
use Database\Seeders\PilotSeeder;
use Database\Seeders\SceneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The scene map's demo data: venues and a month of shows around Hartford,
 * built on the pilot's acts.
 */
class SceneSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('riff.media.disk'));
    }

    public function test_it_seeds_shows_and_venues_onto_the_map_and_can_run_twice(): void
    {
        $this->seed(PilotSeeder::class);
        Queue::fake();
        $this->seed(SceneSeeder::class);

        // Placed as the geocoder would, so no geocoding is needed to demo.
        Queue::assertNothingPushed();

        $area = ['south' => 41.4, 'west' => -73.1, 'north' => 42.0, 'east' => -72.3];
        $shows = $this->getJson(route('map.shows', $area))->assertOk()->json('data');
        $venues = collect($this->getJson(route('map.venues', $area))->json('data'))->pluck('name');

        $this->assertGreaterThanOrEqual(8, count($shows));
        $this->assertContains(true, array_column($shows, 'isOneOff'));
        // The dormant venue has faded; the private one is at its town.
        $this->assertNotContains('The Old Foundry', $venues);
        $this->assertContains('Basement Signal', $venues);
        $this->assertNull(Venue::where('name', 'Basement Signal')->value('street_address'));

        $counts = [Venue::count(), Show::count()];
        $this->seed(SceneSeeder::class);
        $this->assertSame($counts, [Venue::count(), Show::count()]);
    }
}
