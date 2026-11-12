<?php

namespace Database\Factories\Support;

/**
 * Real towns around the default map center, with the coordinates of each
 * town's center.
 *
 * Seeded records must look like geocoded ones: Riff only ever knows a
 * person's town, and the geocoder resolves a town to its center point. A
 * random coordinate in a bounding box does neither -- it lands pins in
 * Long Island Sound and pairs them with an unrelated town name.
 *
 * Several artists in one town therefore share a point exactly, as they do
 * in production; the map's clustering is what separates them.
 */
class Towns
{
    /**
     * @var list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private const TOWNS = [
        // Connecticut
        ['Hartford', 'CT', 41.7658, -72.6734],
        ['West Hartford', 'CT', 41.7620, -72.7420],
        ['New Haven', 'CT', 41.3083, -72.9279],
        ['Bridgeport', 'CT', 41.1865, -73.1952],
        ['Stamford', 'CT', 41.0534, -73.5387],
        ['Norwalk', 'CT', 41.1177, -73.4082],
        ['Waterbury', 'CT', 41.5582, -73.0515],
        ['Danbury', 'CT', 41.3948, -73.4540],
        ['New Britain', 'CT', 41.6612, -72.7795],
        ['Middletown', 'CT', 41.5623, -72.6506],
        ['Meriden', 'CT', 41.5382, -72.8070],
        ['Bristol', 'CT', 41.6718, -72.9493],
        ['Manchester', 'CT', 41.7759, -72.5215],
        ['Storrs', 'CT', 41.8084, -72.2495],
        ['Willimantic', 'CT', 41.7107, -72.2081],
        ['Norwich', 'CT', 41.5243, -72.0759],
        ['New London', 'CT', 41.3557, -72.0995],
        ['Mystic', 'CT', 41.3543, -71.9665],
        ['Torrington', 'CT', 41.8007, -73.1212],
        ['Wallingford', 'CT', 41.4570, -72.8231],
        ['Enfield', 'CT', 41.9762, -72.5918],
        ['Hamden', 'CT', 41.3959, -72.8968],
        ['Milford', 'CT', 41.2223, -73.0565],
        ['Glastonbury', 'CT', 41.7123, -72.6082],
        ['Trumbull', 'CT', 41.2428, -73.2007],
        ['Fairfield', 'CT', 41.1408, -73.2613],
        ['Newtown', 'CT', 41.4145, -73.3037],
        ['Chester', 'CT', 41.4026, -72.4512],
        ['Collinsville', 'CT', 41.8126, -72.9205],
        ['Putnam', 'CT', 41.9151, -71.9093],

        // Massachusetts
        ['Springfield', 'MA', 42.1015, -72.5898],
        ['Holyoke', 'MA', 42.2043, -72.6162],
        ['Westfield', 'MA', 42.1251, -72.7495],
        ['Northampton', 'MA', 42.3251, -72.6412],
        ['Amherst', 'MA', 42.3732, -72.5199],
        ['Greenfield', 'MA', 42.5876, -72.5995],
        ['Pittsfield', 'MA', 42.4501, -73.2454],
        ['Worcester', 'MA', 42.2626, -71.8023],
        ['Framingham', 'MA', 42.2793, -71.4162],
        ['Boston', 'MA', 42.3601, -71.0589],
        ['Cambridge', 'MA', 42.3736, -71.1097],
        ['Somerville', 'MA', 42.3876, -71.0995],
        ['Lowell', 'MA', 42.6334, -71.3162],
        ['Fall River', 'MA', 41.7015, -71.1550],
        ['New Bedford', 'MA', 41.6362, -70.9342],

        // Rhode Island
        ['Providence', 'RI', 41.8240, -71.4128],
        ['Pawtucket', 'RI', 41.8787, -71.3826],
        ['Cranston', 'RI', 41.7798, -71.4373],
        ['Warwick', 'RI', 41.7001, -71.4162],
        ['Woonsocket', 'RI', 42.0029, -71.5148],
        ['Newport', 'RI', 41.4901, -71.3128],
        ['Westerly', 'RI', 41.3776, -71.8273],

        // New York (Hudson Valley)
        ['Poughkeepsie', 'NY', 41.7004, -73.9210],
        ['Beacon', 'NY', 41.5048, -73.9696],
        ['Kingston', 'NY', 41.9270, -73.9974],
        ['Hudson', 'NY', 42.2529, -73.7910],
        ['Albany', 'NY', 42.6526, -73.7562],
        ['White Plains', 'NY', 41.0340, -73.7629],
        ['Brewster', 'NY', 41.3973, -73.6171],
    ];

    /**
     * @return array{city: string, state: string, lat: float, lng: float}
     */
    public static function random(): array
    {
        [$city, $state, $lat, $lng] = self::TOWNS[array_rand(self::TOWNS)];

        return ['city' => $city, 'state' => $state, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * @return array{city: string, state: string, lat: float, lng: float}
     */
    public static function named(string $city): array
    {
        foreach (self::TOWNS as [$name, $state, $lat, $lng]) {
            if ($name === $city) {
                return ['city' => $name, 'state' => $state, 'lat' => $lat, 'lng' => $lng];
            }
        }

        throw new \InvalidArgumentException("Unknown seed town [{$city}].");
    }

    /**
     * @return list<array{city: string, state: string, lat: float, lng: float}>
     */
    public static function all(): array
    {
        return array_map(
            fn (array $town) => ['city' => $town[0], 'state' => $town[1], 'lat' => $town[2], 'lng' => $town[3]],
            self::TOWNS,
        );
    }
}
