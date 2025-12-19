<?php

namespace App\DTO;

class MapPoint
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public float $lat,
        public float $lng,
        public ?string $label = null,
    ) {}

    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            'label' => $this->label,
        ];
    }
}
