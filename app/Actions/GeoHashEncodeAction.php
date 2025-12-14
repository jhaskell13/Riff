<?php

namespace App\Actions;

class GeoHashEncodeAction
{
    private static $base32Chars = '0123456789bcdefghjkmnpqrstuvwxyz';

    public function handle($latitude, $longitude, $precision = 9): string
    {
        $bits = '';
        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;

        $evenBit = true; // True for longitude, false for latitude

        for ($i = 0; $i < $precision * 5; $i++) { // Each Base32 char represents 5 bits
            if ($evenBit) {
                // Longitude
                $mid = ($lonMin + $lonMax) / 2;
                if ($longitude > $mid) {
                    $bits .= '1';
                    $lonMin = $mid;
                } else {
                    $bits .= '0';
                    $lonMax = $mid;
                }
            } else {
                // Latitude
                $mid = ($latMin + $latMax) / 2;
                if ($latitude > $mid) {
                    $bits .= '1';
                    $latMin = $mid;
                } else {
                    $bits .= '0';
                    $latMax = $mid;
                }
            }
            $evenBit = !$evenBit;
        }

        $geohash = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            $value = bindec($chunk); // Convert binary chunk to decimal
            $geohash .= self::$base32Chars[$value]; // Map to Base32 character
        }

        return $geohash;
    }
}
