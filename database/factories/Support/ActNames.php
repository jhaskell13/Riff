<?php

namespace Database\Factories\Support;

/**
 * Invented names for seeded acts.
 *
 * Faker's company names ("Smith, Johnson and Sons") read as businesses, and
 * a map covered in them looks like a directory rather than a local scene --
 * which matters, because the seeded dataset is what Riff is demonstrated
 * with. These are assembled from word lists instead, so they read like
 * bands, and they are deliberately invented: seeding the names of real
 * local acts would put words in the mouths of people who never agreed to
 * be here.
 */
class ActNames
{
    private const QUALIFIERS = [
        'Velvet', 'Paper', 'Iron', 'Neon', 'Quiet', 'Hollow', 'Golden',
        'Electric', 'Midnight', 'Broken', 'Silver', 'Wild', 'Glass', 'Pale',
        'Northern', 'Lonesome', 'Static', 'Crooked', 'Sunken', 'Cheap',
        'Slow', 'Bitter', 'Open', 'Winter', 'Second',
    ];

    private const NOUNS = [
        'Arcade', 'Harbor', 'Lantern', 'Signal', 'Parade', 'Echoes',
        'Weather', 'Avenue', 'Current', 'Divide', 'Gospel', 'Tide', 'Union',
        'Wire', 'Choir', 'Orchard', 'Mill', 'Radio', 'Canyon', 'Pines',
        'Transit', 'Hour', 'Season', 'Ledger', 'Afternoon',
    ];

    private const ALIASES = [
        'Aperture', 'Cassette', 'Halflight', 'Lowtide', 'Mirrorwork',
        'Nightshift', 'Oakline', 'Pressboard', 'Quarry', 'Rainout',
        'Sidecar', 'Tapehead', 'Undertow', 'Vantage', 'Wavelength',
    ];

    /**
     * A name that suits the kind of act. A band reads as a band, a solo
     * singer as a person, a producer or DJ as an alias.
     */
    public static function for(string $artistType): string
    {
        return match ($artistType) {
            'solo' => fake()->name(),
            'rapper' => fake()->randomElement([
                fake()->firstName().' '.strtoupper(fake()->randomLetter()).'.',
                self::alias(),
            ]),
            'producer' => self::alias().fake()->randomElement([' Beats', ' Audio', '', ' Sound']),
            'dj' => 'DJ '.self::alias(),
            default => self::group(),
        };
    }

    private static function group(): string
    {
        $qualifier = fake()->randomElement(self::QUALIFIERS);
        $noun = fake()->randomElement(self::NOUNS);

        return fake()->randomElement([
            "The {$qualifier} {$noun}",
            "{$qualifier} {$noun}",
            "The {$noun}",
            $qualifier.strtolower($noun),
        ]);
    }

    private static function alias(): string
    {
        return fake()->randomElement(self::ALIASES);
    }
}
