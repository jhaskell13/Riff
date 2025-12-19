<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistDeleteRequest;
use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Models\Artist;
use App\Models\ArtistUser;
use Illuminate\Support\Facades\Auth;
use Inertia\Response as InertiaResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ArtistZoneController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $artists = $user
            ->artists()
            ->select('artists.id', 'artists.name', 'artists.genre', 'artists.hometown', 'artists.stateOrProvince', 'artists.country')
            ->with(['members' => fn ($q) => $q->select('users.id', 'users.name')])
            ->get();

        return Inertia::render('ArtistZone/Index', [
            'artists' => $artists
        ]);
    }

    public function create(): InertiaResponse
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

        $artist->members()->attach($user->id);

        return to_route('artists.index');
    }

    public function destroy(ArtistDeleteRequest $request, Artist $artist): Response
    {
        $artist->delete();

        return redirect()->back();
    }
}
