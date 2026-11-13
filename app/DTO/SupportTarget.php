<?php

namespace App\DTO;

/**
 * A support link Riff accepted: its type, the canonical form of the URL,
 * and the host, which is shown to the reader so nobody clicks blind.
 *
 * There is deliberately no embed URL here, unlike MusicEmbed. Support links
 * always leave Riff; nothing on this object may ever reach an iframe.
 */
class SupportTarget
{
    public function __construct(
        public string $type,
        public string $label,
        public string $url,
        public string $host,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'url' => $this->url,
            'host' => $this->host,
        ];
    }
}
