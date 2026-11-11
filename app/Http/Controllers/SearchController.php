<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Name lookups for the show form's pickers. Separate from the map search
 * on purpose: an artist hidden from the map still has a public profile and
 * still plays shows, so it must be findable for a lineup.
 */
class SearchController extends Controller
{
    private const LIMIT = 10;

    public function artists(Request $request): JsonResponse
    {
        $term = $this->term($request);

        $artists = Artist::query()
            ->whereRaw('lower(name) like ?', [$term])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'slug', 'hometown', 'stateOrProvince']);

        return response()->json(['data' => $artists->map(fn (Artist $artist) => [
            'id' => $artist->id,
            'name' => $artist->name,
            'area' => $artist->hometown.', '.$artist->stateOrProvince,
        ])->values()]);
    }

    public function venues(Request $request): JsonResponse
    {
        $term = $this->term($request);

        $venues = Venue::query()
            ->whereRaw('lower(name) like ?', [$term])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $venues->map(fn (Venue $venue) => [
            'id' => $venue->id,
            'name' => $venue->name,
            'area' => $venue->area,
            'kindLabel' => $venue->kind_label,
        ])->values()]);
    }

    /**
     * A lower-cased LIKE pattern. The user's own % and _ are stripped
     * rather than escaped, because LIKE escaping differs across the three
     * drivers CI runs on; names rarely contain either.
     */
    private function term(Request $request): string
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        return '%'.Str::lower(str_replace(['%', '_'], '', trim($q))).'%';
    }
}
