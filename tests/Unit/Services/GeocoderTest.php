<?php

namespace Tests\Unit\Services;

use App\Services\Geocoder;
use Tests\TestCase;

class GeocoderTest extends TestCase
{
    public function test_returns_valid_coords(): void
    {
        $service = app(Geocoder::class);

        $results = $service->fromAddress('Bloomfield', 'CT');

        $expected = (object) [
            'lat' => 41.826488,
            'lng' => -72.7300945,
        ];

        $this->assertEquals($expected, $results);
    }

    public function test_returns_default_coords_on_invalid_input(): void
    {
        $service = app(Geocoder::class);

        $results = $service->fromAddress('thisshouldfail', 'becauseitsinvalid');

        $this->assertEquals($results, $service->defaultCoords());
    }
}
