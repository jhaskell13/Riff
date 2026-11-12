<?php

namespace App\Http\Controllers;

use App\Mail\ListingReplyMail;
use App\Models\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Replying to a listing: stored, then relayed to the poster by email. The
 * poster's address is never exposed, and the replier's only as far as the
 * contact they type. Interim until in-app messaging (Phase 4).
 */
class ListingReplyController extends Controller
{
    public function store(Request $request, Listing $listing): RedirectResponse
    {
        $this->authorize('reply', $listing);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:1000'],
            'contact' => ['required', 'string', 'min:3', 'max:200'],
        ], [
            'contact.required' => 'Say how they can reach you — an email, a phone number, a handle.',
        ]);

        // One reply per person per listing (the table's unique index is
        // the backstop for a double submit).
        if ($listing->replies()->where('user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages(['message' => "You've already replied to this listing."]);
        }

        $reply = $listing->replies()->make($validated);
        $reply->forceFill(['user_id' => $request->user()->id])->save();

        $poster = $listing->user;

        if ($poster !== null) {
            Mail::to($poster)->queue(new ListingReplyMail($reply));
        }

        return back();
    }
}
