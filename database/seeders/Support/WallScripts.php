<?php

namespace Database\Seeders\Support;

/**
 * Scripted wall conversations for the demo.
 *
 * These exist so the seeded app *shows what a wall is for* rather than
 * proving the schema works: a band announcing a record and answering the
 * questions it gets, fans talking to each other rather than only to the
 * band, a question nobody has answered yet, and one wall busy enough that
 * the "show more comments" and "see all replies" controls actually appear.
 *
 * A script is a list of posts, newest last. Each post is
 * `['voice' => 'artist'|'fan', 'content' => string, 'comments' => [...]]`,
 * and each comment may carry `replies` (one level only — that is as deep as
 * the product goes).
 *
 * Fictional acts only. A wall of invented fans saying invented things about
 * a real band attributes them a community they never had; see the dataset's
 * rules in database/seeders/data/pilot-scene.json.
 */
final class WallScripts
{
    /**
     * A deliberately busy wall: enough top-level comments to page past
     * `riff.wall.comments_per_post`, and one comment with more replies than
     * `riff.wall.replies_per_comment`, so both "show more" controls are
     * visible in the demo without anyone having to type a word.
     *
     * @return list<array<string, mixed>>
     */
    public static function busy(): array
    {
        return [
            [
                'voice' => 'artist',
                'content' => "New record is finished. Nine songs, recorded live to tape over two weekends at a friend's garage in Newington. It goes up on Bandcamp Friday and we are playing all of it front to back at the release show.",
                'comments' => [
                    ['voice' => 'fan', 'content' => 'Front to back is the right way to do it. Is the release show all ages?', 'replies' => [
                        ['voice' => 'artist', 'content' => 'All ages, five dollars, doors at seven. We asked for all ages specifically.'],
                        ['voice' => 'fan', 'content' => 'Bringing my little brother then. He has never been to one.'],
                        ['voice' => 'fan', 'content' => 'First show at an all ages basement gig is a good first show.'],
                        ['voice' => 'fan', 'content' => 'Mine was in a church hall in 2014. Still think about it.'],
                    ]],
                    ['voice' => 'fan', 'content' => 'Two weekends? The last one took you a year.', 'replies' => [
                        ['voice' => 'artist', 'content' => 'We stopped trying to fix things in the mix and just played them properly instead.'],
                    ]],
                    ['voice' => 'fan', 'content' => 'Tape or vinyl?', 'replies' => [
                        ['voice' => 'artist', 'content' => 'Tapes first, fifty of them. Vinyl if the tapes sell.'],
                    ]],
                    ['voice' => 'fan', 'content' => 'Will the old songs be in the set as well or strictly the new record?', 'replies' => []],
                    ['voice' => 'fan', 'content' => 'That garage has a better room sound than most studios around here, honestly.', 'replies' => []],
                    ['voice' => 'fan', 'content' => 'Any chance you come up to the north end of the state after this?', 'replies' => [
                        ['voice' => 'artist', 'content' => 'Working on it. If you know a room up there, tell us.'],
                    ]],
                    ['voice' => 'fan', 'content' => 'Been playing the single on repeat for a week. My housemates are sick of it.', 'replies' => []],
                    ['voice' => 'fan', 'content' => 'Congratulations, genuinely. Nine songs live to tape is not nothing.', 'replies' => []],
                ],
            ],
            [
                'voice' => 'fan',
                'content' => 'Saw these three in a basement with about forty people last night. Go while you can still stand that close.',
                'comments' => [
                    ['voice' => 'fan', 'content' => 'I was at the March one. Ears rang for a day.', 'replies' => []],
                    ['voice' => 'artist', 'content' => 'Thanks for coming out. Next one is announced this week.', 'replies' => []],
                ],
            ],
            [
                'voice' => 'artist',
                'content' => 'Practice space flooded. If anyone has a room in the area we could use for a month, we are asking.',
                'comments' => [
                    ['voice' => 'fan', 'content' => 'My uncle has a garage in Newington. I can ask him tonight.', 'replies' => [
                        ['voice' => 'artist', 'content' => 'That would genuinely save us. Answer the listing and we will sort it out.'],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Walls written for specific real acts, around their actual records
     * and the rooms they actually play.
     *
     * **These are simulated conversations.** The acts, their members, the
     * records and the venues are real and public; the words are invented,
     * and so are the fans saying them. That is the point of the demo --
     * a recruiting pitch has to show what a populated scene looks like --
     * and it is the one thing in the dataset that is made up about a real
     * subject. The seeders refuse production; see the dataset's rules.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function forActs(): array
    {
        return [
            'Perennial' => [
                [
                    'voice' => 'artist',
                    'content' => 'Modernism is out. Ten songs, twenty minutes — we are not sorry. Records at the merch table and we will sign anything that is not a cheque.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Twenty minutes is the correct length for an album and nobody wants to admit it.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Chad has a whole speech about this. Ask him at the merch table at your own risk.'],
                            ['voice' => 'fan', 'content' => 'Going to ask him specifically so I can hear the speech.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Saw you at a tiny room in New Haven years ago and the organ was louder than the drums. Still think about it.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'That is the intended mix, to be clear.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Any chance of a Hartford date for the record?', 'replies' => []],
                    ],
                ],
                [
                    'voice' => 'fan',
                    'content' => 'If you have never seen this band: they play about ninety seconds a song and do not stop between them. Bring water.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Can confirm. I was not prepared the first time.', 'replies' => []],
                    ],
                ],
            ],

            'Boundaries' => [
                [
                    'voice' => 'artist',
                    'content' => 'Hartford. Friday. Loud. Bring your friend who says they do not like heavy music.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Is this all ages? Trying to bring my cousin, he is 16 and has never been to a hardcore show.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'All ages. Tell him to stand near the back for the first two songs and then do whatever he wants.'],
                            ['voice' => 'fan', 'content' => 'That is genuinely good advice for a first show.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Death Is Little More has not left my car since it came out.', 'replies' => []],
                        ['voice' => 'fan', 'content' => 'Will you play anything off Your Receding Warmth or is this a new set?', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Two from it. You will know which two.'],
                        ]],
                    ],
                ],
            ],

            'The Lonesome While' => [
                [
                    'voice' => 'artist',
                    'content' => 'The Lost of Us is up. Some of these have been sitting in a hard drive since before the pandemic and we honestly thought nobody would hear them.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Five years is a long wait and it was worth it. The second half of this record is something else.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'That is the half that took five years, funnily enough.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Empty Mansions got me through a bad winter. Good to have you back.', 'replies' => []],
                    ],
                ],
            ],

            'West End Blend' => [
                [
                    'voice' => 'artist',
                    'content' => 'Horns are loaded, van is running, see you downtown. Come early — the opener is worth it and we mean that.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'How many of you are there now? I have counted a different number every time.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Eight tonight. It depends on the stage.'],
                            ['voice' => 'fan', 'content' => 'Correct answer.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Erica going into the second verse of Say Hey live is the single best thing in Connecticut music.', 'replies' => []],
                    ],
                ],
            ],

            'Joey Batts & Them' => [
                [
                    'voice' => 'artist',
                    'content' => 'Hip-Hop for the Homeless is back this December. Same as every year: come out, bring what you can, we will play as long as they let us.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Been to three of these now. Best room in Hartford every single time.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Appreciate you. Tell one person who has not been.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Is there a list of what to bring or just cash?', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Either. Both. Whatever you have.'],
                        ]],
                    ],
                ],
            ],

            'Anxious' => [
                [
                    'voice' => 'artist',
                    'content' => 'Bambi is out in the world. Thanks to everyone who has been here since the Little Green House days.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'I was at one of the first shows in a basement in Fairfield. This is wild to see.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Those rooms are the reason any of this happened.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Any CT dates or is it all out of state again?', 'replies' => []],
                    ],
                ],
            ],

            'Cheem' => [
                [
                    'voice' => 'artist',
                    'content' => 'nu pop from nu england. new one is loud and stupid in the correct way.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'I cannot explain this band to my friends and I have stopped trying.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'That is the review we want on the poster.'],
                            ['voice' => 'fan', 'content' => 'Put it on a shirt.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Guilty Pleasure to Power Move is a genuinely unhinged run of records.', 'replies' => []],
                    ],
                ],
            ],
        ];
    }

    /**
     * Walls of ordinary size, one per act. Between them they cover the
     * shapes a real wall takes: an announcement with a thread, fans
     * answering each other with no band involvement, a thank-you, an
     * unanswered question, and a quiet wall with a single post.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function scripts(): array
    {
        return [
            // A record out, and the band answering.
            [
                [
                    'voice' => 'artist',
                    'content' => 'New single is up. Thanks to everyone who came out to the release show — that was the loudest that room has ever been.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Been waiting for this since you played it in May. The bridge is enormous.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'That bridge took four practices and one argument. Glad it landed.'],
                            ['voice' => 'fan', 'content' => 'Worth every practice.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Any chance of a tape run?', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Fifty of them, at the merch table Friday. First come.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'The mix is so much better than the demos. Who recorded it?', 'replies' => []],
                    ],
                ],
                [
                    'voice' => 'fan',
                    'content' => 'Does anyone know if the Friday show is all ages? Bringing my younger brother and I do not want to get turned away at the door.',
                    'comments' => [
                        ['voice' => 'artist', 'content' => 'All ages. Bring him.', 'replies' => []],
                    ],
                ],
            ],
            // Fans talking to each other; the band stays out of it.
            [
                [
                    'voice' => 'fan',
                    'content' => 'Who else is going Saturday? Trying to work out if it is worth driving over from the shoreline.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Driving from New Haven. Happy to take two people if anyone needs a ride.', 'replies' => [
                            ['voice' => 'fan', 'content' => 'I would take you up on that if the offer is still good Friday.'],
                            ['voice' => 'fan', 'content' => 'Same, I can chip in for gas.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'It is worth the drive. I have seen them three times this year.', 'replies' => []],
                    ],
                ],
                [
                    'voice' => 'artist',
                    'content' => 'Set times are up at the door. We are on second, about nine.',
                    'comments' => [],
                ],
            ],
            // A quiet wall: one update, one reply.
            [
                [
                    'voice' => 'artist',
                    'content' => 'Demos are finally mixed. Streaming everywhere this week.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Finally. Been asking for two years.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'We know. Sorry. It is here now.'],
                        ]],
                    ],
                ],
            ],
            // Looking for a member, in public.
            [
                [
                    'voice' => 'artist',
                    'content' => 'Looking for a drummer who can make Thursdays work. We have a room, a van that passed inspection, and about twenty minutes of material.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'My old bandmate might be around. What are you playing?', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Fast and short. Think the first record, not the second.'],
                        ]],
                        ['voice' => 'fan', 'content' => 'Is this a paid thing or a split-the-door thing?', 'replies' => [
                            ['voice' => 'artist', 'content' => 'Split the door, evenly, always.'],
                        ]],
                    ],
                ],
            ],
            // A question nobody has answered yet. Walls have these.
            [
                [
                    'voice' => 'fan',
                    'content' => 'Is the merch table taking cash only or is there a card reader these days?',
                    'comments' => [],
                ],
            ],
            // Thanks after a show, with the band answering a logistics question.
            [
                [
                    'voice' => 'artist',
                    'content' => 'Thanks to the crowd last night for singing the second verse when I forgot it. That was genuinely the best part of the set.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'We had your back. Mostly.', 'replies' => []],
                        ['voice' => 'fan', 'content' => 'Did someone leave a black denim jacket by the merch table? Asking for a friend who is me.', 'replies' => [
                            ['voice' => 'artist', 'content' => 'It is in the van. Catch us Friday and it is yours.'],
                        ]],
                    ],
                ],
            ],
            // An in-store, and a recommendation chain between fans.
            [
                [
                    'voice' => 'artist',
                    'content' => 'Playing an in-store Saturday afternoon. Short set, no amps, free.',
                    'comments' => [
                        ['voice' => 'fan', 'content' => 'Acoustic versions of these songs is a strange and excellent idea.', 'replies' => [
                            ['voice' => 'fan', 'content' => 'They did this last year and it was better than the full band show, do not tell them.'],
                            ['voice' => 'artist', 'content' => 'We can read.'],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
