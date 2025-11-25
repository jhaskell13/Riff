<?php

namespace App\Http\Requests\ArtistZone;

use App\Models\Artist;
use App\Models\ArtistUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ArtistDeleteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $userBelongsToArtist = ArtistUser::where('user_id', Auth::id())
            ->where('artist_id', $this->route('artist')->id)
            ->exists();

        return Auth::check() && $userBelongsToArtist;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }
}
