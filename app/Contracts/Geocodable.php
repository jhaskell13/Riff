<?php

namespace App\Contracts;

/**
 * @property float|null $lat
 * @property float|null $lng
 */
interface Geocodable
{
    /**
     * The address parts to geocode: [city, state/province, country].
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public function geocodableAddress(): array;

    /**
     * The attribute names whose change should trigger re-geocoding.
     *
     * @return list<string>
     */
    public function geocodableAttributes(): array;
}
