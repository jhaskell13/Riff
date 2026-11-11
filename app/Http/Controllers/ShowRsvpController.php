<?php

namespace App\Http\Controllers;

use App\Models\Show;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Going / interested. Private by default; a fan opts in to having their
 * name listed.
 */
class ShowRsvpController extends Controller
{
    public function store(Request $request, Show $show): RedirectResponse
    {
        $this->authorize('rsvp', $show);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:going,interested'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $show->attendees()->syncWithoutDetaching([
            $request->user()->id => [
                'status' => $validated['status'],
                'is_public' => (bool) ($validated['is_public'] ?? false),
            ],
        ]);

        return back();
    }

    public function destroy(Request $request, Show $show): RedirectResponse
    {
        $show->attendees()->detach($request->user()->id);

        return back();
    }
}
