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
        $columns = ['artists.id', 'artists.name', 'artists.genre', 'artists.hometown', 'artists.stateOrProvince', 'artists.country', 'artists.image_url'];

        $artists = Auth::user()
            ->artists()
            ->select($columns)
            ->with(['members:id,name'])
            ->get()
            ->each->append('address');

        $archivedArtists = Auth::user()
            ->artists()
            ->onlyTrashed()
            ->select($columns)
            ->get()
            ->each->append('address');

        return Inertia::render('ArtistZone/Index', [
            'artists' => $artists,
            'archivedArtists' => $archivedArtists,
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
            'artist' => $artist->load('members:id,name')->append('address'),
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

    public function restore(Artist $artist): RedirectResponse
    {
        $this->authorize('restore', $artist);

        $artist->restore();

        return to_route('artists.show', $artist);
    }
}
