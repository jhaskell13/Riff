<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Http\Requests\ArtistZone\ArtistUpdateRequest;
use App\Models\Artist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ArtistZoneController extends Controller
{
    public function index(): Response
    {
        $artists = Auth::user()
            ->artists()
            ->select('artists.id', 'artists.name', 'artists.genre', 'artists.hometown', 'artists.stateOrProvince', 'artists.country', 'artists.image_url')
            ->with(['members:id,name'])
            ->get();

        return Inertia::render('ArtistZone/Index', [
            'artists' => $artists,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Artist::class);

        return Inertia::render('ArtistZone/Create');
    }

    public function store(ArtistStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Artist::class);

        $artist = Artist::create($request->validated());
        $artist->members()->attach($request->user());

        return to_route('artists.show', $artist);
    }

    public function show(Artist $artist): Response
    {
        $this->authorize('view', $artist);

        return Inertia::render('ArtistZone/Show', [
            'artist' => $artist->load('members:id,name'),
            'canManage' => Auth::user()->can('update', $artist),
        ]);
    }

    public function edit(Artist $artist): Response
    {
        $this->authorize('update', $artist);

        return Inertia::render('ArtistZone/Edit', [
            'artist' => $artist,
        ]);
    }

    public function update(ArtistUpdateRequest $request, Artist $artist): RedirectResponse
    {
        $this->authorize('update', $artist);

        $artist->update($request->validated());

        return to_route('artists.show', $artist);
    }

    /**
     * Archive (soft delete) the artist so a shared identity
     * can be restored if a member removes it by mistake.
     */
    public function destroy(Artist $artist): RedirectResponse
    {
        $this->authorize('delete', $artist);

        $artist->delete();

        return to_route('artists.index');
    }
}
