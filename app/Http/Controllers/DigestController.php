<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stopping the digest.
 *
 * Deliberately outside the auth group and reached by a **signed** link:
 * making someone sign in before they can stop email is how complaints
 * become spam reports. The signature is the authorization -- it is
 * time-limited, scoped to one account id, and can only have come from an
 * email Riff sent.
 */
class DigestController extends Controller
{
    public function unsubscribe(Request $request, User $user): Response
    {
        // The `signed` middleware has already rejected a tampered or
        // expired link; this only has to honour it.
        $user->forceFill(['digest_frequency' => 'off'])->save();

        return Inertia::render('Digest/Unsubscribed', [
            'email' => $user->email,
            'settingsUrl' => route('notifications.index'),
        ]);
    }
}
