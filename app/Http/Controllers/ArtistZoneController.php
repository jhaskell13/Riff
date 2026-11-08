<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistDeleteRequest;
use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Models\Artist;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ArtistZoneController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $artists = $user
            ->artists()
            ->select('artists.id', 'artists.name', 'artists.genre', 'artists.hometown', 'artists.stateOrProvince', 'artists.country')
            ->with(['members:id,name']) // Select [id, name] from users who are members of each artist
            ->get();

        return Inertia::render('ArtistZone/Index', [
            'artists' => $artists,
        ]);
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('ArtistZone/Create');
    }

    public function store(ArtistStoreRequest $request): Response
    {
        $artist = Artist::create($request->validated());
        $artist->members()->attach(Auth::id());

        return to_route('artists.index');
    }

    public function destroy(ArtistDeleteRequest $request, Artist $artist): Response
    {
        $artist->delete();

        return redirect()->back();
    }
}
