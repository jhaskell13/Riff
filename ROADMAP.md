# Riff development roadmap

## What Riff is

**Riff is a local music scene app.** Open it wherever you are and see the scene around you: the bands and artists, the shows coming up, the venues and DIY spaces they play, and the acts looking for a drummer. Find one you love, become a fan, and their page becomes a place to hear from them, talk with other fans, and support them by buying their music and merch.

The test case for every feature: *someone is curious about their city's underground punk scene and opens Riff.* Within a minute they should see who plays, where, and when, and how to get involved. Riff is not a streaming service, a national chart, a ticketing aggregator, or another general social feed. Plenty of products already do those things. None of them make a local scene visible.

### The loop

**Find your scene → go to a show → become a fan → hear from and support the artist → come back for the next show.**

Phases 0–1 built the first step (finding local artists on a map). Everything planned below extends that loop. Nothing here replaces it.

### Principles

1. **Place first.** Every artist, show, venue, and listing has a location, and discovery starts from a place. Content that isn't anchored somewhere doesn't belong in Riff.
2. **Scenes, not feeds.** Updates hang off an artist, a show, a venue, or a place. There is no global timeline, no infinite algorithmic scroll, and no ranking by popularity alone. A new band with one show deserves to be found as much as the biggest act in town.
3. **The artist page is the fans' home.** Fan interaction happens on the artist's own page, where the artist speaks as the artist and can moderate their own space, not in a shared public square.
4. **Support reaches the artist.** Riff points money to where artists already get paid (Bandcamp, their own store, the door) before it ever handles money itself, and it doesn't take a cut of the music.
5. **Underground-safe.** Public locations are approximate and chosen by the artist. DIY and house-show addresses are never shown publicly by default. All-ages status is first-class information, and a listener's location is never public.
6. **Dense and real beats broad and invented.** One scene that really works is worth more than thin coverage of a region. Seeded data stays fictional; real profiles come only from people who asked to be listed.

### Vocabulary

- **Scene:** a place × a sound ("Hartford punk"). It starts as a *view*, a named area plus a genre family that resolves to a map, a list of shows, and a set of artists. It only becomes a stored entity if pilots show it needs its own pages or moderators.
- **Fan:** a listener who follows an artist. The existing `artist_follows` relationship is the fan relationship. It is named for what it means to people, not what it does in the database.
- **Show:** a dated event with a lineup of one or more artists, usually at a venue.
- **Venue:** anywhere music happens: club, bar, all-ages hall, record store, DIY space, house. Each has a visibility rule for its address.
- **Listing:** a structured "looking for" post, such as members wanted, band wanted, a bill or opener wanted, or a practice space. It is local, tagged by genre and instrument, and expires.

## Current foundation (November 2026, post-Phase 1)

- Laravel 13, Inertia 3, and Vue 3 app with enforced email verification, Pint and PHPStan, and a CI test matrix (SQLite, MySQL, Postgres) that gates deploys. The deploy keeps `APP_KEY` across releases.
- Artist identities with a full lifecycle (create, profile, edit, archive, restore) behind membership policies. Profiles carry curated genres, a re-encoded photo, and a playable music link that is rebuilt into an embed when read.
- A public, rate-limited map as the front door: viewport or radius queries, server-side clustering, text search across names, towns, and genres, filters, a synchronized list, and compact artist cards with distance, preview, and follow.
- Onboarding that splits a first-time guest into listener and artist paths and carries the choice through registration and email verification.
- Safety: reporting from every surface, per-listener blocking, and a moderator queue whose actions can all be undone and are all logged.
- Geocoding is queued and runs once per address change. Failures leave a record off the map rather than placing it at a fake location.
- A seeded, entirely fictional Greater Hartford pilot scene (`riff:seed-pilot`). Real artists have not been recruited yet.

### Where the current build diverges from the new direction

These aren't bugs, but each one pulls Riff toward the generic product it shouldn't become. Each is addressed in a phase below.

- **The feed is global.** `/feed` shows every published post from everyone. → Replaced in Phase 3 by artist pages and a personal "your scene" home.
- **Post authorship is ambiguous.** Posts carry a `user_id` and a nullable `artist_id`, so "the band announced this" and "a fan wrote this" look the same. → Phase 3 gives posts an explicit context.
- **Artist profiles require sign-in.** `ArtistPolicy::view` allows guests, but the `artists` resource route sits inside the `auth`/`verified` group, so a shared profile link hits a login wall. → Fixed first in Phase 2.
- **Events are Ticketmaster-first.** The only `EventProvider` covers arena and club tours, not the basement show on Friday. → Phase 2 makes artist- and venue-submitted shows the primary source and demotes imports to filler with clear labels.
- **The join stub promises listings that don't exist.** The artist-create "join" mode says only "artists actively looking for members" will be shown. → Phase 2 adds Listings; Phase 4 adds the join-request flow they feed.
- **The livestream prototype still ships.** It isn't linked from the nav, but its `/streams` routes are live and its transport is a dead end (see Phase 5). Leave it alone and replace it rather than extend it.

## Completed

### 0. Make the prototype dependable — COMPLETE (November 2026)

Membership authorization through `ArtistPolicy`/`LiveStreamPolicy` on every state-changing endpoint; full artist lifecycle with recoverable archive; persisted, validated, rate-limited feed posting; viewport-bounded map with geocoding queued at save time; hardened external APIs; email verification actually enforced; offline test coverage across auth, access control, mass assignment, rate limits, map, and geocoding; README and CI/deploy docs.

### 1. Ship the local discovery MVP — COMPLETE (November 2026)

Useful artist profiles (type, genres, bio, location, photo, playable music); map cards, a synchronized list, and empty/loading/error states; search and filters for genre, type, and radius on mobile and desktop; a welcome overlay with listener and artist onboarding paths carried through verification; a dense fictional pilot scene; reporting, blocking, and a moderator queue with four recoverable decisions and an audit trail.

**Carried forward:** recruit real, consenting artists, watch where onboarding loses people, and see whether report volume stays manageable for one moderator.

## Roadmap

### 2. Put the whole scene on the map

**Goal:** Opening Riff in a town shows its scene, not just a set of pins: who plays, what's on, where, and who's looking for whom.

Build in this order. Each step is useful by itself.

1. **Public artist pages.** Move `artists.show` out of the auth group, since the policy already permits guests. Account-specific controls (follow, report, block) prompt guests to register with `intent=fan`, as the map card already does. Profiles get shareable, slug-based URLs.
2. **Venues.** Venue profiles cover name, kind (club, bar, all-ages hall, record store, DIY space, house), approximate public area, age policy, and an **address visibility** rule: `public`, `on_request`, or `hidden`. DIY and house venues default to `on_request`, and their map pins sit at the neighbourhood level, never at the door. Venues are community-submitted and moderated. Claiming a venue comes later, in Phase 4.
3. **Shows.** Members submit shows for their artist: date and time, venue (or a "location TBA / ask a punk" option), a **lineup** of several Riff artists or free-text names for acts not on Riff, door price or ticket link, and age policy. Each lineup entry is confirmed by that artist's members, so one band can't list another without consent. Fans can mark a show "going" or "interested". Imported listings carry a visible source label and never outrank artist-confirmed shows.
4. **Map layers.** The map and its list switch between Artists, Shows (with a date window: tonight, this weekend, this month), and Venues. Each layer keeps the covering-index discipline `ArtistMapSearch` follows. Generalise the search shape only where it stays index-friendly, and benchmark each layer against a large dataset before shipping it.
5. **Scene views.** A scene is a named place plus a genre family, at a URL anyone can share (for example `/scenes/hartford-ct/punk`). It resolves to that area's artists, upcoming shows, venues, and listings in one view. It uses a radius around the place's centre rather than strict town boundaries, because scenes cross town lines. Genre families (Punk ⊃ Hardcore, Emo, Ska-punk, …) are curated in `config/riff.php` alongside the genre vocabulary.
6. **Listings ("looking for").** Structured, opt-in posts: members wanted (by instrument), band wanted (a musician looking to join), opener or bill wanted, practice space. Each has an area, genres, and an **expiry** so the board doesn't fill up with stale posts. Responding sends a relayed message: Riff emails the poster with the reply and whatever contact details the responder chose to share. That avoids building a messaging system and exposes no addresses. Listings show up in scene views and as a map layer.
7. **Events data sources.** Keep the `EventProvider` contract for local-first sources (venue calendars, promoter feeds). Ticketmaster stays only where it adds local coverage. It is cached, labelled, and degrades quietly.
8. **Pilot data.** Extend `PilotSeeder` with invented venues (including a DIY space with an on-request address), shows over the coming weeks with shared bills, and a few listings. Everything stays fictional, as now.

**Done when:** A guest opens a scene link (or the map in their town), sees that week's shows and the venues hosting them, opens a band's public page from a lineup, and finds a "drummer wanted" listing, all without signing in. Signing in lets them mark a show, follow, or reply to a listing.

**Success signals:** Shows get submitted by artists, not seeded; scene links are shared; guests reach artist pages from shows; "going" marks turn into return visits; listings get replies before they expire.

### 3. Make the artist page a home for fans

**Goal:** Becoming a fan means something: a place to hear from the artist, meet other fans, and support the band.

- **Posts with explicit context.** Members post **as the artist** (updates, releases, show announcements), and those posts render as the artist. Fans post **on the artist's wall**, and those render as the fan. The ambiguous `user_id`/`artist_id` pairing gets an explicit author context, and the feed's existing rule of persisting only `validated()` fields and creating through relations stays.
- **Fan wall and replies.** Fans comment on artist posts and on the wall, and reply to each other. The artist's replies are marked as coming from the artist. This is where the long-hidden comments/likes schema finally ships. Reactions stay small, and like counts don't drive ranking.
- **Artist-level moderation.** Members can hide a wall post or comment and remove a fan from their page's wall. Platform reports still go to the moderation queue. The blocking already in place keeps working in the other direction, so a listener who blocks an artist never sees them.
- **Show threads.** Each show gets a short-lived discussion ("who's going?", "set times?") that closes after the date. Showing your name on the attendee list is opt-in, and only a count is public by default.
- **"Your scene" home.** This replaces the global `/feed`: posts from artists you follow, their upcoming shows, and new shows and listings in your scene, ordered by date and distance, not engagement. A guest's home is the map.
- **Support links.** A structured support section on the artist page with typed links (Bandcamp, own store, merch, tickets, tip jar), parsed and validated the way `MusicLink` handles music. Riff sends people to where the artist already gets paid. It doesn't handle payments in this phase.
- **Notifications.** In-app, plus an email digest, for followed artists' new shows, releases, and posts, and opt-in alerts for "a new show in your scene". Controls apply per artist, and frequency is capped.
- **Fan counts and local fans.** Show how many fans an artist has and how many are local, as a count only. No individual fan's location is ever shown.

**Done when:** A fan follows a band from a show, sees the band's announcement and other fans' comments on the band's page, gets notified about the band's next show, and clicks through to buy the record, while the band can moderate its own wall.

**Success signals:** Artists post as their identity without prompting; fans comment and reply to each other; support links get clicks; notifications bring people back to shows rather than prompting people to turn them off.

### 4. Help scenes grow from within

**Goal:** Riff connects the people who make a scene run, not just the people watching it.

- **Member roles and join requests.** Replace the create-page stub with requests to join an artist, approval by existing members, and explicit roles. A members-wanted listing can be answered with a join request.
- **Venue and promoter accounts.** Claim a venue (with verification), keep its calendar, and confirm shows booked there. Promoters can post bills on behalf of several artists, and each artist still confirms.
- **Community profiles,** only if pilots ask for them: record stores, labels, zines, rehearsal spaces. They use the same location and visibility rules as venues.
- **Discovery quality signals.** "New in the scene", "playing soon", and "rarely heard" sit alongside distance. Profile-completeness guidance tells artists how to become easier to find. Popularity is never the only ranking.
- **More scenes.** Expand only as artist density supports it, and treat each new area as a pilot with its own recruiting.
- **Account privacy and data.** Data export and deletion, visibility controls, and spam controls tuned to real abuse patterns from the pilot. Moderation moves from a single flag to roles if volume requires it.
- **Native support — decision gate.** Selling digital releases, merch pre-orders, or show tickets directly through Riff (for example through Stripe Connect, where Riff never holds artists' funds). Pursue it only if support-link data shows artists lose sales to the extra hop. Taxes, fulfilment, refunds, and payouts are real costs to scope first.

### 5. Live together (long-term)

**Goal:** Fans of a band share moments as they happen, without building real-time media infrastructure before demand is proven.

- **Listening parties first.** An artist schedules a premiere of their own release; fans join at a set time, playback is synchronized, and fans chat live with the band in the room. Only members can host, and only audio the artist uploads, because synchronized playback of third-party embeds is unreliable and usually against the platforms' terms. Chat runs over private broadcast channels scoped to the party. Recording the chat, reminders, and a replay window come after the first ones run.
- **Sessions.** Intimate live performances for fans and newcomers, either open or fans-only, with a scheduled time, a reminder, and chat. Use a managed real-time media provider. Riff stores the session's metadata and the provider's references, never video chunks. **Retire the current prototype transport** (base64 chunks over public channels) rather than build on it. Define latency, cost, moderation, and recording limits before launch.
- **Ticketed sessions,** only after the Phase 4 support gate has been decided.

**Decision gate:** Continue live investment only if listening parties draw repeat attendance and sessions meet their latency, reliability, safety, and cost targets.

### Parked

- **Remote live jams** (synchronized remote performance): a research problem, not a product feature. Revisit only with a specific technical spike and explicit latency goals.
- **A global or algorithmic feed, national charts, and follower leaderboards:** contrary to the principles above.

## Cross-cutting quality work

- **Location and privacy:** Artists, venues, and shows use approximate, chosen public areas. DIY addresses stay hidden unless their visibility rule allows them. Listener locations are never public. Location changes clear stale coordinates immediately, as artists already do.
- **Safety at scene scale:** Every new surface (shows, venues, listings, walls, show threads) is reportable through the single `POST /reports` endpoint, and every moderation action stays recoverable and logged. Lineup confirmation and listing expiry exist partly to prevent abuse.
- **Accessibility and mobile:** Most scene-browsing happens on a phone, often on the way to a show. Keyboard-operable map alternatives, screen-reader-friendly lists, readable contrast, and reduced motion.
- **Performance:** Every map layer is bounded and index-covered. Scene views are cacheable for guests. Personal views stay uncached only where they have to be, following the same rule as `MapFilters::hiddenFor`.
- **Reliability:** Imports and geocoding run in the queue, provider failures degrade quietly, and someone actually watches failed jobs.
- **Operations:** Backups, error monitoring, and a lightweight release checklist before real users depend on Riff.

## Recommended next milestone

Start Phase 2 **and** begin recruiting at the same time. The pitch to a real band is far stronger with "list your shows and your scene shows up" than with "put a pin on a map".

1. Make artist pages public (small, and it unblocks sharing).
2. Build venues and shows. Shows give scenes something to come back for.
3. Pick **one scene** to pilot, for example Greater Hartford punk/DIY, and recruit a handful of artists plus one or two venues or promoters who will actually post their calendar. A dense, real scene in one genre is a better test than a thin layer across all of them.

Before real users arrive: watch the failed-jobs table, and decide whether moderation needs more than one flag.

## Questions for the pilot

- Is a scene defined by **where** (my town) or by **what** (punk across the region)? How wide does a scene's radius need to be before it feels alive?
- Who keeps the show calendar current: artists, venues, or promoters? Whoever it is decides who Phase 4 builds tools for.
- Are DIY venues willing to be listed at all, even with on-request addresses? What do they need to trust it?
- Do fans talk to each other on artist pages, or only to the artist? Do show threads get used?
- Do support links get clicked, and do artists want Riff to handle sales, or prefer the stores they already use?
- Would artists host a listening party, and would their fans show up at a fixed time?
