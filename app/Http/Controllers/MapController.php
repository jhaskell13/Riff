<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArtistMarkerResource;
use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    /**
     * The map shell. Markers are fetched by the client for the visible
     * viewport via artists(), so this stays a cheap render.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $center = ($user->lat !== null && $user->lng !== null)
            ? ['lat' => $user->lat, 'lng' => $user->lng]
            : config('riff.map.default_center');

        return Inertia::render('Map/Index', [
            'center' => $center,
            'hasOwnLocation' => $user->lat !== null && $user->lng !== null,
        ]);
    }

    /**
     * Artists inside the given viewport, capped so a zoomed-out view
     * cannot pull the entire table.
     */
    public function artists(Request $request): AnonymousResourceCollection
    {
        $bounds = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gte:south'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'east' => ['required', 'numeric', 'between:-180,180', 'gte:west'],
        ]);

        $artists = Artist::query()
            ->geolocated()
            ->visibleOnMap()
            ->withinBounds($bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'])
            ->with('genres:id,name')
            ->select(['id', 'name', 'artist_type', 'hometown', 'stateOrProvince', 'lat', 'lng', 'image_path', 'music_provider'])
            ->limit(config('riff.map.max_markers'))
            ->get();

        return ArtistMarkerResource::collection($artists);
    }
}
