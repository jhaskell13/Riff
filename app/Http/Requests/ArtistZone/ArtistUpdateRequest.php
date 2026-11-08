<?php

namespace App\Http\Requests\ArtistZone;

class ArtistUpdateRequest extends ArtistStoreRequest
{
    // Same validation rules as creation; authorization is handled
    // by ArtistPolicy in the controller.
}
