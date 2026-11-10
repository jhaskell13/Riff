<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Blocking is a listener's own view of Riff: the artist disappears from
 * their map and feed and is told nothing. Both actions are idempotent and
 * return to where the button was pressed, exactly like following.
 */
class ArtistBlockController extends Controller
{
    public function store(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('block', $artist);

        $user = $request->user();
        $user->blockedArtists()->syncWithoutDetaching([$artist->id]);

        // Hiding someone you follow leaves a contradiction behind; drop the
        // follow rather than keeping them in a list of artists you asked
        // not to see.
        $user->followedArtists()->detach($artist->id);

        return back();
    }

    public function destroy(Request $request, Artist $artist): RedirectResponse
    {
        // Unblocking is always allowed, including for an artist the viewer
        // has since joined: otherwise the block would be unreachable.
        $request->user()->blockedArtists()->detach($artist->id);

        return back();
    }
}
