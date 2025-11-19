<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Models\Artist;
use App\Models\ArtistUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;
use Inertia\Inertia;

class ArtistZoneController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (! $user->artists->count()) {
            return to_route('artist_zone');
        }

        return Inertia::render('ArtistZone/Index', [
            'artists' => $user->artists,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ArtistZone/Create');
    }

    public function store(ArtistStoreRequest $request)
    {
        $user = Auth::user();

        $artist = Artist::create([
            'name' => $request->name,
            'genre' => $request->genre,
            'hometown' => $request->hometown,
            'stateOrProvince' => $request->stateOrProvince,
            'country' => $request->country,
            'biography' => $request->description,
        ]);

        ArtistUser::create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
        ]);

        return redirect()->back();
    }
}
