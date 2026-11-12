<?php

namespace Tests\Feature;

use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\User;
use App\Services\Geocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeocodingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_artist_without_coordinates_queues_geocoding(): void
    {
        Queue::fake();

        Artist::factory()->create(['lat' => null, 'lng' => null]);

        Queue::assertPushed(GeocodeAddress::class, 1);
    }

    public function test_creating_an_artist_with_coordinates_does_not_queue_geocoding(): void
    {
        Queue::fake();

        Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);

        Queue::assertNothingPushed();
    }

    public function test_changing_an_artists_hometown_requeues_geocoding(): void
    {
        // Pinned: the factory picks a random town, and if it picked the
        // destination the update would change nothing.
        $artist = Artist::factory()->create(['hometown' => 'Hartford']);

        Queue::fake();
        $artist->update(['hometown' => 'New Haven']);

        Queue::assertPushed(GeocodeAddress::class, 1);
    }

    public function test_an_address_change_immediately_invalidates_stale_coordinates(): void
    {
        $artist = Artist::factory()->create(['hometown' => 'Hartford', 'lat' => 41.5, 'lng' => -72.5]);

        Queue::fake();
        $artist->update(['hometown' => 'New Haven']);

        // Off the map until the queued geocode succeeds; never shown at the old city.
        $this->assertNull($artist->fresh()->lat);
        $this->assertNull($artist->fresh()->lng);
    }

    public function test_the_job_skips_the_write_when_the_address_changed_mid_flight(): void
    {
        config(['services.google.maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 41.3082138, 'lng' => -72.9250518]]],
                ],
            ]),
        ]);

        $artist = Artist::factory()->create();
        $job = new GeocodeAddress($artist);

        // Address changes after the job captured its model but before it runs.
        Artist::withoutEvents(fn () => $artist->fresh()->update(['hometown' => 'Somewhere Else', 'lat' => null, 'lng' => null]));

        $job->handle(app(Geocoder::class));

        $this->assertNull($artist->fresh()->lat);
    }

    public function test_renaming_an_artist_does_not_requeue_geocoding(): void
    {
        $artist = Artist::factory()->create();

        Queue::fake();
        $artist->update(['name' => 'Renamed']);

        Queue::assertNothingPushed();
    }

    public function test_changing_a_users_city_requeues_geocoding(): void
    {
        // Pinned: Providence is one of the factory's random towns, so an
        // unpinned user was already there about 1 run in 56 (it surfaced on
        // the MySQL leg, but it is not driver-specific).
        $user = User::factory()->create(['city' => 'Hartford']);

        Queue::fake();
        $user->update(['city' => 'Providence']);

        Queue::assertPushed(GeocodeAddress::class, 1);
    }

    public function test_the_job_stores_resolved_coordinates_without_retriggering_observers(): void
    {
        config(['services.google.maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 41.3082138, 'lng' => -72.9250518]]],
                ],
            ]),
        ]);

        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);

        Queue::fake();
        (new GeocodeAddress($artist))->handle(app(Geocoder::class));

        $this->assertSame(41.3082138, $artist->fresh()->lat);
        $this->assertSame(-72.9250518, $artist->fresh()->lng);
        Queue::assertNothingPushed();
    }

    public function test_the_job_leaves_coordinates_untouched_when_geocoding_fails(): void
    {
        // No API key configured: the geocoder returns null.
        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);

        (new GeocodeAddress($artist))->handle(app(Geocoder::class));

        $this->assertSame(41.5, $artist->fresh()->lat);
    }

    public function test_the_backfill_command_queues_only_records_missing_coordinates(): void
    {
        Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);
        Artist::factory()->create(['lat' => null, 'lng' => null]);
        User::factory()->create(['lat' => null, 'lng' => null]);

        Queue::fake();

        $this->artisan('riff:geocode-missing')
            ->expectsOutputToContain('Queued 2 geocoding jobs.')
            ->assertSuccessful();

        Queue::assertPushed(GeocodeAddress::class, 2);
    }
}
