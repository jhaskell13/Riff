<?php

namespace App\Http\Controllers;

use App\Actions\GeoHashEncode;
use App\Models\Artist;
use App\Services\EventFinder;
use App\Services\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(
        Geocoder $geocoder,
        EventFinder $eventFinder,
        GeoHashEncode $geoHashEncode,
    ): Response
    {
        $user = Auth::user();
        $location = $geocoder->fromAddress($user->city, $user->state, $user->country);

        $localArtists = [];
        $artists = Artist::all();

        foreach ($artists as $artist) {
            $localArtists[] = (object) [
                'name' => $artist->name,
                'location' => $geocoder->fromAddress($artist->hometown, $artist->stateOrProvince, $artist->country),
            ];
        }

        return Inertia::render('Map/Index', [
            'currentLocation' => [
                'lat' => $location->lat,
                'lng' => $location->lng,
            ],
            'localArtists' => $localArtists,
            'events' => [],//$eventFinder->getNearbyEvents($geoHashEncode->execute($location->lat, $location->lng)),
        ]);
    }
}
