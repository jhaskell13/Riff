<?php

namespace App\DTO;

/**
 * A playable music link that Riff recognised: the canonical form of the
 * link the artist gave us, plus the embed URL we build ourselves.
 *
 * embedUrl is never derived from raw user input -- MusicLink extracts the
 * provider's own identifier, validates its character set, and composes the
 * URL from a fixed template, so nothing user-supplied reaches an iframe
 * src verbatim. A null embedUrl means "we can link to it but not play it".
 */
class MusicEmbed
{
    public function __construct(
        public string $provider,
        public string $label,
        public string $url,
        public ?string $embedUrl = null,
    ) {}

    public function isPlayable(): bool
    {
        return $this->embedUrl !== null;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'url' => $this->url,
            'embed_url' => $this->embedUrl,
        ];
    }
}
