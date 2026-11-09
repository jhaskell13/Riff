<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistPhotoRequest;
use App\Models\Artist;
use App\Services\ArtistPhotos;
use Illuminate\Http\RedirectResponse;

/**
 * Photos have their own endpoints rather than riding along on the profile
 * PATCH: PHP does not parse multipart bodies on PATCH, and a photo is
 * naturally changed on its own anyway.
 */
class ArtistPhotoController extends Controller
{
    public function __construct(private ArtistPhotos $photos) {}

    public function store(ArtistPhotoRequest $request, Artist $artist): RedirectResponse
    {
        $this->authorize('update', $artist);

        $this->photos->store($artist, $request->file('photo'));

        return back();
    }

    public function destroy(Artist $artist): RedirectResponse
    {
        $this->authorize('update', $artist);

        $this->photos->remove($artist);

        return back();
    }
}
