<?php

namespace Database\Factories\Support;

/**
 * Short, plausible bios for seeded acts: what a local act might write about
 * itself, naming the town, because that is what a listener scans for.
 */
class ActBios
{
    private const OPENINGS = [
        'band' => [
            'Four of us, one practice space above a laundromat in %s.',
            'A %s band that started as a covers project and never quite went back.',
            'Loud guitars, two singers, and a van that mostly starts. Based in %s.',
            'We have been playing basements and back rooms around %s since 2021.',
            'Five of us from %s, which is three more than the stage usually fits.',
            'Started in a garage in %s and have been slowly getting better in public.',
            'A %s band, together long enough that nobody remembers whose idea it was.',
        ],
        'solo' => [
            'Songs written at the kitchen table in %s, played anywhere that will have them.',
            'Singer and guitarist out of %s. Mostly quiet songs, occasionally not.',
            'I write about leaving %s and about coming back to it.',
            'One voice, one guitar, and a loop pedal I am still learning. Based in %s.',
            'Playing around %s for about six years now, mostly as the opener nobody expected.',
        ],
        'rapper' => [
            'Writing and recording in %s. Everything you hear was made in a bedroom.',
            'Out of %s. Two mixtapes down, working on the third.',
            '%s raised. I say what happened and leave the rest alone.',
            'From %s. I write on the bus and record when the house is empty.',
            'Rapper and engineer in %s. I record myself, so the mistakes are mine.',
        ],
        'producer' => [
            'Beats and production out of %s. Open to vocalists; send me something.',
            'Making instrumentals in %s, mostly sample-led, mostly at night.',
            'Producer in %s. I mix for other local acts and release my own records between jobs.',
            'Hardware and a borrowed sampler, in a spare room in %s.',
            'I make tracks in %s and give most of them away to singers who need them.',
        ],
        'dj' => [
            'Playing records around %s. Residencies, weddings, and the occasional warehouse.',
            'DJ based in %s. Mostly house, some disco, nothing before midnight.',
            'Vinyl only, which is a mistake I keep making. Playing out around %s.',
            'I run a monthly night in %s and play other people\'s the rest of the time.',
            'Open format, reading the room. Available anywhere near %s.',
        ],
        'other' => [
            'A loose collective of players around %s. The line-up changes with the night.',
            'Making music in %s with whoever is free that week.',
            'Somewhere between a band and a reading group, based in %s.',
        ],
    ];

    private const CLOSINGS = [
        'New record out this spring.',
        'Shows most months — check the socials.',
        'Always looking for people to play with.',
        'Recording at home, mixing badly, enjoying it.',
        'Happy to play anywhere within an hour of home.',
        'We book our own shows and split the door.',
        '',
    ];

    public static function for(string $artistType, string $town): string
    {
        $openings = self::OPENINGS[$artistType] ?? self::OPENINGS['other'];
        $bio = sprintf(fake()->randomElement($openings), $town);
        $closing = fake()->randomElement(self::CLOSINGS);

        return trim($closing === '' ? $bio : $bio.' '.$closing);
    }
}
