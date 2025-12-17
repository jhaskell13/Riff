<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Geocoder
{
    protected const GEOCODE_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    protected const DEFAULT_LAT = 41.727173300123091;

    protected const DEFAULT_LNG = -72.824225127696991;

    public function defaultCoords(): object
    {
        return (object) [
            'lat' => self::DEFAULT_LAT,
            'lng' => self::DEFAULT_LNG,
        ];
    }

    public function fromAddress(string $city, string $state, ?string $country = null): object
    {
        $address  = "$city, $state";
        $address .= isset($country) ? ", $country" : "";

        $results = Cache::rememberForever("gmaps.geocode.address.$address", function () use ($address) {
            try {
                $response = Http::get(self::GEOCODE_ENDPOINT, [
                    'address' => $address,
                    'key'     => config('services.google.maps.key'),
                ]);

                $response = json_decode($response->body());

                if ($response?->status != 'OK' || ! $response?->results[0]) {
                    \Log::info("Invalid geocoder request: [STATUS CODE $response?->status]");
                    return $this->defaultCoords();
                }

                $location = $response->results[0]->geometry->location;

                return (object) [
                    'lat' => round($location->lat, 7),
                    'lng' => round($location->lng, 7),
                ];
            } catch (\Throwable $e) {
                \Log::error('Geocoder error: ' . $e->getMessage());
                return $this->defaultCoords();
            }
        });

        if ($results == $this->defaultCoords()) {
            Cache::forget("gmaps.geocode.address.$address");
        }

        return $results;
    }
}
