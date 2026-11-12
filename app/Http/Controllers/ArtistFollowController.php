<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Services\FanCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Following is the first commitment a listener makes after a discovery, so
 * it has to be cheap and repeatable: both actions are idempotent and return
 * to wherever the listener pressed the button -- a map card or a profile.
 */
class ArtistFollowController extends Controller
{
    public function __construct(private FanCounts $fanCounts) {}

    public function store(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('follow', $artist);

        $request->user()->followedArtists()->syncWithoutDetaching([$artist->id]);
        $this->fanCounts->forget($artist);

        return back();
    }

    public function destroy(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('follow', $artist);

        $request->user()->followedArtists()->detach($artist->id);
        $this->fanCounts->forget($artist);

        return back();
    }
}
