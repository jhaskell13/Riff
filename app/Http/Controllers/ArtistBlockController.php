<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Blocking is a listener's own view of Riff: the artist disappears from
 * their map and feed and is told nothing. Idempotent, like following.
 */
class ArtistBlockController extends Controller
{
    public function store(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('block', $artist);

        $user = $request->user();
        $user->blockedArtists()->syncWithoutDetaching([$artist->id]);

        // Following someone you asked not to see is a contradiction.
        $user->followedArtists()->detach($artist->id);

        return back();
    }

    public function destroy(Request $request, Artist $artist): RedirectResponse
    {
        // Always allowed, or a block could become unremovable.
        $request->user()->blockedArtists()->detach($artist->id);

        return back();
    }
}
