<?php

namespace App\Http\Controllers;

use App\DTO\MapPoint;
use App\Http\Requests\Map\MapArtistsRequest;
use App\Http\Requests\Map\MapSceneRequest;
use App\Http\Resources\ArtistCardResource;
use App\Models\Artist;
use App\Models\Place;
use App\Services\Map\ArtistMapSearch;
use App\Services\Map\SceneMapSearch;
use App\Support\GenreCatalog;
use App\Support\MapCache;
use App\Support\ShowWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
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
        $hasOwnLocation = $user !== null && $user->lat !== null && $user->lng !== null;

        return Inertia::render('Map/Index', [
            'center' => $hasOwnLocation
                ? ['lat' => $user->lat, 'lng' => $user->lng]
                : config('riff.map.default_center'),
            'hasOwnLocation' => $hasOwnLocation,
            'filterOptions' => $this->filterOptions($request),
            // Guests browse the same map; actions needing an account
            // become a prompt to sign in rather than a failed request.
            'canFollow' => $user !== null,
            'maxMarkers' => (int) config('riff.map.max_markers'),
        ]);
    }

    /**
     * Artists matching the given area and filters. See MapArtistsRequest
     * for parameters and ArtistMapSearch for the response shape.
     */
    public function artists(MapArtistsRequest $request, ArtistMapSearch $search): JsonResponse
    {
        $filters = $request->filters();

        return response()
            ->json($search->search($filters))
            // A short private cache lets the browser reuse a response when a
            // listener toggles a filter off and back on, or pans back.
            ->header('Cache-Control', $filters->isPersonal() ? 'private, no-store' : 'private, max-age=30');
    }

    /**
     * Shows in the area within the chosen date window, nearest-first
     * within each day. See SceneMapSearch.
     */
    public function shows(MapSceneRequest $request, SceneMapSearch $search): JsonResponse
    {
        $filters = $request->filters();

        return response()
            ->json($search->shows($filters))
            ->header('Cache-Control', $filters->hiddenFor !== null ? 'private, no-store' : 'private, max-age=30');
    }

    /**
     * Active venues in the area. See SceneMapSearch.
     */
    public function venues(MapSceneRequest $request, SceneMapSearch $search): JsonResponse
    {
        $filters = $request->filters();

        return response()
            ->json($search->venues($filters))
            ->header('Cache-Control', 'private, max-age=30');
    }

    /**
     * Active listings ("looking for") in the area. See SceneMapSearch.
     */
    public function listings(MapSceneRequest $request, SceneMapSearch $search): JsonResponse
    {
        $filters = $request->filters();

        return response()
            ->json($search->listings($filters))
            ->header('Cache-Control', $filters->hiddenFor !== null ? 'private, no-store' : 'private, max-age=30');
    }

    /**
     * The compact card for one artist, opened from a marker or list row.
     * Hidden artists 404 here exactly as they are absent from artists().
     */
    public function card(Request $request, Artist $artist): ArtistCardResource
    {
        $this->authorize('view', $artist);

        abort_unless($artist->show_on_map && $artist->lat !== null && $artist->lng !== null, 404);

        $origin = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'unit' => ['nullable', Rule::in(['mi', 'km'])],
        ]);

        $point = isset($origin['lat'])
            ? new MapPoint(round((float) $origin['lat'], 3), round((float) $origin['lng'], 3))
            : null;

        $artist->load('genres:id,name')->loadCount('followers');

        return (new ArtistCardResource($artist))->withDistanceFrom(
            $point,
            $origin['unit'] ?? $request->user()?->distance_unit ?? config('riff.distance.default_unit'),
        );
    }

    /**
     * Towns that have artists on the map, matched by prefix. This is how a
     * guest -- or anyone -- moves the map to a place by name without the
     * request path ever calling a geocoder: the coordinates are the ones
     * already stored for artists there. Only towns with visible artists
     * are offered, which is also the only kind worth jumping to.
     */
    public function places(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $term = mb_strtolower(trim($validated['q']));
        $ttl = max(1, (int) config('riff.map.cache_ttl_seconds'));

        $places = Cache::remember(
            'riff.map.places:'.MapCache::version().':'.sha1($term),
            $ttl,
            fn () => Artist::query()
                ->geolocated()
                ->visibleOnMap()
                ->whereRaw("lower(hometown) like ? escape '!'", [ArtistMapSearch::escapeLike($term).'%'])
                ->toBase()
                // Aliased through the builder so the grammar quotes the
                // camel-cased column correctly on every driver.
                ->select(['hometown as name', 'stateOrProvince as region', 'country'])
                ->selectRaw('avg(lat) as lat, avg(lng) as lng, count(*) as artist_count')
                ->groupBy('hometown', 'stateOrProvince', 'country')
                ->orderByDesc('artist_count')
                ->orderBy('hometown')
                ->limit(8)
                ->get()
                ->map(fn ($row) => [
                    'name' => $row->name,
                    'region' => $row->region,
                    'country' => $row->country,
                    'lat' => round((float) $row->lat, 5),
                    'lng' => round((float) $row->lng, 5),
                    'artistCount' => (int) $row->artist_count,
                ])
                ->all(),
        );

        // Each town's scene page, where it has one (one query for all).
        $scenes = Place::query()
            ->whereIn('slug', array_map(fn (array $place) => Place::slugFor($place['name'], $place['region']), $places))
            ->pluck('slug')
            ->flip();

        $places = array_map(function (array $place) use ($scenes) {
            $slug = Place::slugFor($place['name'], $place['region']);

            return [...$place, 'sceneUrl' => $scenes->has($slug) ? route('scenes.show', $slug) : null];
        }, $places);

        return response()
            ->json(['data' => $places])
            ->header('Cache-Control', 'private, max-age=60');
    }

    /**
     * Everything the map's filter controls offer, so the client never
     * hard-codes a list that validation might disagree with.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(Request $request): array
    {
        $unit = $request->user()?->distance_unit ?? config('riff.distance.default_unit');

        return [
            'genres' => GenreCatalog::curatedOptions(),
            'types' => collect((array) config('riff.artist.types'))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'radiusOptions' => config('riff.map.radius_options'),
            'unit' => $unit,
            'kinds' => [
                ['value' => 'artists', 'label' => 'Artists', 'icon' => 'groups'],
                ['value' => 'shows', 'label' => 'Shows', 'icon' => 'event'],
                ['value' => 'venues', 'label' => 'Venues', 'icon' => 'storefront'],
                ['value' => 'listings', 'label' => 'Wanted', 'icon' => 'campaign'],
            ],
            'windows' => collect(ShowWindow::OPTIONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'sorts' => [
                ['value' => 'distance', 'label' => 'Nearest'],
                ['value' => 'name', 'label' => 'Name'],
                ['value' => 'newest', 'label' => 'Newest'],
            ],
        ];
    }
}
