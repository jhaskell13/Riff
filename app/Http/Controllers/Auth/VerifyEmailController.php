<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OnboardingIntent;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended($this->destination($request->user()));
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended($this->destination($request->user()));
    }

    /**
     * Where verifying lands someone. Artist creation sits behind this
     * check, so anyone who registered to be discovered was turned away
     * from it a moment ago: resume that path now instead of dropping them
     * on the map to find it again. Once they have an identity the map is
     * the right place, including when an old link is opened twice.
     */
    private function destination(User $user): string
    {
        if ($user->onboarding_intent === OnboardingIntent::Artist && ! $user->artists()->exists()) {
            return route('artists.create', absolute: false);
        }

        return route('map.index', absolute: false).'?verified=1';
    }
}
