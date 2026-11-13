<?php

namespace App\Rules;

use App\Services\SupportLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only links SupportLink will publish for the given type, so an
 * artist finds out at submit time that their Bandcamp field points
 * somewhere that isn't Bandcamp.
 */
class SupportUrl implements ValidationRule
{
    public function __construct(private ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && SupportLink::parse($value, $this->type) !== null) {
            return;
        }

        $hosts = SupportLink::hostsFor($this->type);

        if ($hosts !== [] && is_string($value) && $value !== '') {
            $fail('This must be a link on '.implode(', ', $hosts).'.');

            return;
        }

        $fail('Enter a full link starting with https://.');
    }
}
