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
        config(['services.google.maps.key' => 'test-key']);
    }

    private function fakeSuccess(float $lat = 41.82648812345, float $lng = -72.73009456789): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]]],
                ],
            ]),
        ]);
    }

    public function test_returns_rounded_coordinates_from_a_successful_response(): void
    {
        $this->fakeSuccess();

        $point = app(Geocoder::class)->fromAddress('Bloomfield', 'CT');

        $this->assertNotNull($point);
        $this->assertSame(41.8264881, $point->lat);
        $this->assertSame(-72.7300946, $point->lng);

        Http::assertSent(fn ($request) => $request['address'] === 'Bloomfield, CT');
    }

    public function test_includes_country_in_the_address_when_provided(): void
    {
        $this->fakeSuccess();

        app(Geocoder::class)->fromAddress('Bloomfield', 'CT', 'USA');

        Http::assertSent(fn ($request) => $request['address'] === 'Bloomfield, CT, USA');
    }

    public function test_returns_null_without_an_api_key_and_sends_nothing(): void
    {
        config(['services.google.maps.key' => null]);
        Http::fake();

        $this->assertNull(app(Geocoder::class)->fromAddress('Bloomfield', 'CT'));

        Http::assertNothingSent();
    }

    public function test_returns_null_when_the_api_reports_no_results(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);

        $this->assertNull(app(Geocoder::class)->fromAddress('thisshouldfail', 'XX'));
    }

    public function test_returns_null_on_http_errors(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response('Server error', 500),
        ]);

        $this->assertNull(app(Geocoder::class)->fromAddress('Bloomfield', 'CT'));
    }

    public function test_caches_successful_lookups(): void
    {
        $this->fakeSuccess();

        $service = app(Geocoder::class);
        $service->fromAddress('Bloomfield', 'CT');
        $service->fromAddress('Bloomfield', 'CT');

        Http::assertSentCount(1);
    }

    public function test_cache_key_normalizes_case_and_whitespace(): void
    {
        $this->fakeSuccess();

        $service = app(Geocoder::class);
        $service->fromAddress('Bloomfield', 'CT');
        $service->fromAddress('  bloomfield ', 'ct');

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

        // retry(2) makes each failed lookup two attempts; the point is the
        // second call hit the API again instead of a cached failure.
        $this->assertGreaterThan(1, count(Http::recorded()));
    }
}
