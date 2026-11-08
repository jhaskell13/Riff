<?php

namespace Tests\Unit\Services;

use App\Services\Geocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_returns_rounded_coords_from_a_successful_response(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 41.82648812345, 'lng' => -72.73009456789]]],
                ],
            ]),
        ]);

        $results = app(Geocoder::class)->fromAddress('Bloomfield', 'CT');

        $this->assertEquals((object) ['lat' => 41.8264881, 'lng' => -72.7300946], $results);

        Http::assertSent(fn ($request) => $request['address'] === 'Bloomfield, CT');
    }

    public function test_includes_country_in_the_address_when_provided(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]]]],
            ]),
        ]);

        app(Geocoder::class)->fromAddress('Bloomfield', 'CT', 'USA');

        Http::assertSent(fn ($request) => $request['address'] === 'Bloomfield, CT, USA');
    }

    public function test_returns_default_coords_when_the_api_reports_no_results(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);

        $service = app(Geocoder::class);

        $this->assertEquals($service->defaultCoords(), $service->fromAddress('thisshouldfail', 'becauseitsinvalid'));
    }

    public function test_returns_default_coords_when_the_request_fails(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response('Server error', 500),
        ]);

        $service = app(Geocoder::class);

        $this->assertEquals($service->defaultCoords(), $service->fromAddress('Bloomfield', 'CT'));
    }

    public function test_caches_successful_lookups(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]]]],
            ]),
        ]);

        $service = app(Geocoder::class);
        $service->fromAddress('Bloomfield', 'CT');
        $service->fromAddress('Bloomfield', 'CT');

        Http::assertSentCount(1);
    }

    public function test_does_not_cache_failed_lookups(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);

        $service = app(Geocoder::class);
        $service->fromAddress('nowhere', 'XX');
        $service->fromAddress('nowhere', 'XX');

        Http::assertSentCount(2);
    }
}
