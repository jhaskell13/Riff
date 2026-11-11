<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OnboardingIntent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            // Which path the visitor chose on the map. An unrecognised value
            // is simply no choice: the form then asks.
            'intent' => OnboardingIntent::parse($request->query('intent'))?->value,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'intent' => ['nullable', Rule::enum(OnboardingIntent::class)],
        ]);

        $intent = OnboardingIntent::parse($request->input('intent'));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            // Guessed from the stated country; changeable on the profile.
            'distance_unit' => User::distanceUnitForCountry($request->country),
            'onboarding_intent' => $intent,
        ]);

        event(new Registered($user));

        Auth::login($user);

        // Someone who came to be discovered has one more step to take, and
        // it is the point of their visit; everyone else goes to the map.
        // Email verification stands in front of artist creation -- the
        // verification controller resumes this path once they are through.
        return redirect($intent === OnboardingIntent::Artist
            ? route('artists.create', absolute: false)
            : route('map.index', absolute: false));
    }
}
