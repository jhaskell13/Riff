<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An https link that must live on one of the given domains (or a subdomain
 * of one). Keeps a "Bandcamp" field from carrying somewhere unrelated, which
 * is the cheapest defence against a profile used as a link farm.
 */
class LinkOnHost implements ValidationRule
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(private array $domains) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;
        $scheme = is_string($value) ? parse_url($value, PHP_URL_SCHEME) : null;

        if (! is_string($host) || ! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            $fail('The :attribute must be a valid link.');

            return;
        }

        $host = strtolower($host);

        foreach ($this->domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return;
            }
        }

        $fail('The :attribute must be a link on '.implode(' or ', $this->domains).'.');
    }
}
