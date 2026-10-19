<?php

namespace App\Http\Controllers;

use App\Services\MapBuilder;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function __invoke(MapBuilder $mapBuilder): Response {
        $data = $mapBuilder->buildMapDataForUser(Auth::user());

        return Inertia::render('Map/Index', [
            'currentLocation' => $data['userLocation'],
            'localArtists' => $data['artistLocations'],
        ]);
    }
}
