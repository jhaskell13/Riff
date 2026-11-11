<?php

namespace App\Rules;

use App\Services\MusicLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only links MusicLink can resolve to a known provider, so an
 * artist finds out at submit time that their link will not play.
 */
class PlayableMusicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || MusicLink::parse($value) === null) {
            $fail('Use a track, album, or playlist link from '.implode(', ', MusicLink::PROVIDERS).'.');
        }
    }
}
