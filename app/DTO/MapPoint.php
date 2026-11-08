<?php

namespace App\DTO;

class MapPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {}

    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
