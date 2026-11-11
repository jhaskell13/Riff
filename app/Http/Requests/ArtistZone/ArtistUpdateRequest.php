<?php

namespace App\Http\Requests\ArtistZone;

class ArtistUpdateRequest extends ArtistStoreRequest
{
    /**
     * Same rules as creation, minus the photo: an existing artist changes
     * its photo through ArtistPhotoController, because PHP does not parse
     * multipart PATCH bodies. Authorization is handled by ArtistPolicy.
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['photo']);

        return $rules;
    }
}
