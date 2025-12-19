<?php

namespace App\Http\Controllers;

use App\Actions\BuildArtistMapData;
use App\Actions\GeoHashEncodeAction;
use App\Actions\ResolveUserLocation;
use App\Models\Artist;
use App\Services\EventFinder;
use App\Services\Geocoder;
use App\Services\MapBuilder;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(MapBuilder $mapBuilder): Response {
        $data = $mapBuilder->buildMapDataForUser(Auth::user());

        return Inertia::render('Map/Index', [
            'currentLocation' => $data['userLocation'],
            'localArtists' => $data['artistLocations'],
        ]);
    }
}
