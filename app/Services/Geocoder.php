<?php

namespace App\Services;

use App\DTO\MapPoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Geocoder
{
    protected const GEOCODE_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Resolve an address to coordinates, or null when the address cannot
     * be geocoded. Callers decide what a missing location means; this
     * service never substitutes a fake one.
     *
     * Town-level by default. Only a public venue passes a street: artists
     * and listeners are placed at their town's centre and nothing else.
     */
    public function fromAddress(string $city, string $state, ?string $country = null, ?string $street = null): ?MapPoint
    {
        if (blank(config('services.google.maps.key'))) {
            Log::notice('Geocoder skipped: GOOGLE_MAPS_API_KEY is not configured.');

            return null;
        }

        $address = collect([$street, $city, $state, $country])
            ->filter(fn (?string $part) => filled($part))
            ->map(fn (string $part) => Str::squish($part))
            ->implode(', ');

        $cacheKey = 'geocoder.address.'.md5(Str::lower($address));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return new MapPoint(lat: $cached['lat'], lng: $cached['lng']);
        }

        $point = $this->resolve($address);

        // Only successful lookups are cached; failures stay retryable.
        if ($point !== null) {
            Cache::put($cacheKey, ['lat' => $point->lat, 'lng' => $point->lng], now()->addDays(config('riff.geocoder.cache_ttl_days')));
        }

        return $point;
    }

    protected function resolve(string $address): ?MapPoint
    {
        try {
            $response = Http::timeout(config('riff.geocoder.timeout_seconds'))
                ->retry(2, 200, throw: false)
                ->get(self::GEOCODE_ENDPOINT, [
                    'address' => $address,
                    'key' => config('services.google.maps.key'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Geocoder request failed.', ['address' => $address, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Geocoder returned an HTTP error.', ['address' => $address, 'status' => $response->status()]);

            return null;
        }

        $status = $response->json('status');
        $location = $response->json('results.0.geometry.location');

        if ($status !== 'OK' || ! isset($location['lat'], $location['lng'])) {
            Log::info('Geocoder could not resolve address.', ['address' => $address, 'status' => $status]);

            return null;
        }

        return new MapPoint(
            lat: round((float) $location['lat'], 7),
            lng: round((float) $location['lng'], 7),
        );
    }
}
