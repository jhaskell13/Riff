# Phase 2 implementation plan — put the whole scene on the map

Status: **approved** (November 11, 2026), with revisions from review: slugs follow renames (old links redirect) rather than being frozen, and the map is one unified scene view rather than switchable layers; venues are recurring places and one-off locations belong to shows. **Steps 1–4 are built.**

This turns Phase 2 of [ROADMAP.md](../ROADMAP.md) into eight PR-sized steps. Each step ships on its own, keeps CI green on all three database drivers, and leaves the app better even if we pause after it. Order matters: venues before shows (shows reference venues), shows before map layers (a layer needs data), layers before scenes (a scene is a composed view of the layers), and listings last among the features because they reuse the area/genre machinery everything else establishes.

Conventions that apply to every step, per CLAUDE.md: policies gate every state-changing route; persist only `validated()` and create owned rows through relations; "enum" columns are strings validated by `Rule::in` (the CI matrix exists because real drivers reject what SQLite tolerates); every new surface joins `ReportStoreRequest::SUBJECTS` and `ModerationAction`; new Inertia pages require `npm run build` before `php artisan test`; model `@property` docblocks stay current; all tests offline.

---

## Step 1 — Public artist pages with slugs — ✅ BUILT (November 2026)

**Why first:** smallest step, unblocks sharing, and every later feature (lineups, scene views, listings) links to artist pages that must work for guests.

- Add `artists.slug`: unique, generated from name at creation with a numeric suffix on collision (`the-casket-girls-2`), never all digits (the numeric id redirect would shadow it). Backfill in the migration. **Links are permanent, slugs follow the name:** a rename mints a new slug and parks the old one in `artist_slugs`, where it 301-redirects forever (`Artist::resolveRouteBinding` resolves historical slugs; the controller answers with the canonical URL). Renaming back reclaims the parked slug, and a parked slug is never handed to another artist. Model-managed via creating/updating hooks and `App\Support\ArtistSlugs`; not fillable.
- Routing: the public page is `GET /artists/{artist:slug}` (`artists.show`), registered *outside* the auth group but *after* the auth-only artist-zone routes so `artists/create` never matches as a slug. Old numeric id URLs 301-redirect via a sibling route whose parameter is deliberately `{artistId}` — Laravel strips binding fields from URIs, so `{artist}` and `{artist:slug}` would collide as the same route. No route closures (the deploy runs `route:cache`). Management (index, create, edit, update, destroy, restore, photo, follow, block) stays behind `auth`/`verified` exactly as now.
- `ArtistZoneController@show` currently calls `$user->can(...)` on a possibly-null user — rework for guests: `canManage`/`isFollowing`/`isBlocked` become false, and the Follow button for guests links to `register?intent=fan` (same pattern as the map card). Archived artists stay 404 for the public, visible to members via the zone.
- Rate-limit the public page (reuse the `map` limiter or a sibling `profiles` limiter — decide by what the limiter protects: this is the same public-read class as the map, so sharing `map` is fine).
- Share meta (OpenGraph title/description/image from the profile) so links unfurl.
- Tests: guest sees profile, guest never sees manage controls, id URL redirects, archived 404s for guests, slug collision, rename keeps slug.

**Done when:** a logged-out person opens a shared `/artists/<slug>` link and sees the full profile with a working music embed and a register-to-follow prompt.

## Step 2 — Venues — ✅ BUILT (November 2026)

Revised on review: **a venue is an established place that hosts music regularly** (club, live-music bar, small hall, record store, café, a *named* DIY space with a running calendar). A one-off backyard, park, or house show is **not** a venue: its location belongs to the show (Step 3), so nothing lingers on the map after the night is over. Houses are no longer a venue kind.

- `venues` table: `name`, `slug` (same rename-safe slugs as artists, via the shared `HasHistoricalSlugs` trait + `venue_slugs`), `kind` (`club`/`bar`/`hall`/`record_store`/`cafe`/`diy`/`other`, labels in `config('riff.venues.kinds')`), `address_visibility`, `street_address`, `city`/`state`/`country` (clean names, not the artists table's historical `hometown`/`stateOrProvince`), `age_policy`, `website`, `description`, `lat`/`lng`, `show_on_map`, `submitted_by`, soft deletes, and the covering `venues_map_index` ready for Step 4.
- **Address privacy is two states and "never stored", not rounding.** A `public` venue requires a street address and is geocoded at its door (the geocoder gained an optional street component; everything else in Riff stays town-level). A `private` venue stores **no street at all** — the model's `saving` hook nulls it whatever the request sent — and is pinned at its town's centre like artists are. Riff can't leak an address it doesn't hold. DIY spaces default to private (`config('riff.venues.private_by_default')`). Going private clears the pin immediately and re-geocodes at town level. Public output goes through `VenueResource`, which only ever emits `public_address`.
- Community-submitted by any verified user, published immediately, `venues` limiter (3/minute and 20/day per account). One venue per name per town (case-insensitive) so shows have a single row to point at. `slug`, coordinates, `show_on_map`, and `submitted_by` are not fillable — a submitter can't undo a moderator's hide.
- `VenuePolicy`: public view; any verified user creates; only the submitter edits (claiming is Phase 4).
- Moderation: `venue` joins `ReportStoreRequest::SUBJECTS`; `ModerationAction` gains `hide_venue` (off the map, page stays — for a venue that closed) and `archive_venue` (soft delete, page 404s — for spam). Both recoverable; the queue summarises venues by `public_address` only.
- Pages: public `Venues/Show` (OpenGraph meta, report button for signed-in viewers, "the address is shared by the hosts" for private venues), `Venues/Create` and `Venues/Edit` sharing `Components/VenueFields.vue`, whose opening banner explains venue vs. one-off show. The Artist Zone gained "Venues you added" with an Add a venue button.
- Also fixed in passing: a name that slugs to a route word (`create`, `edit`, `new`) now gets a suffix (`create-band`) — before, an artist called "Create" was unreachable behind `/artists/create`.
- Not yet: venues on the map (Step 4), upcoming shows on the venue page (Step 3), pilot venues in the seeder (Step 7).

**Done when:** a user adds a named DIY space, its page shows only the town with no street stored anywhere, and a moderator can hide or take it down from a report. ✅

## Step 3 — Shows — ✅ BUILT (November 2026)

- **Place:** a show is at a `venue_id` **or** at its own one-off location (`location_name`, `city`/`state`/`country`, `location_note`) — exactly one, enforced by the request (`prohibits`/`required_without`) and by the model's `saving` hook, which clears a venue show's own location columns. One-off locations are geocoded at town level only (`Show` carries the `GeocodableObserver` rules itself, conditioned on being one-off) and never create a venue row. The form's one-off branch tells the poster not to type a street.
- **Time:** stored in UTC with the show's IANA `timezone`; the form sends local date + clock time + timezone (defaulting to the browser's), and every surface renders in the *show's* timezone — "8 PM" means 8 PM where the show is, for every viewer. New or moved times must be in the future (a typo fix on tonight's listing is fine), within a year, doors before start.
- **Host:** every member listing has a `host_artist_id` — one of the poster's artists (checked with `ArtistPolicy::update`), always on its own bill, fixed after creation. Only the host's members edit or cancel. Imported listings (Step 8) will have no host.
- **Lineup consent** (`lineup_entries`, `App\Services\Lineup`): an artist the poster belongs to is confirmed at once (membership is consent); any other Riff artist starts **pending** and appears on no public surface until its own members confirm from the Artist Zone; free-text acts not on Riff need no one's consent. Declining (or dropping out later) removes the artist from every public surface; a declined artist isn't re-asked by ordinary edits. **A material change — date, time, or place — puts every other artist back to pending**: confirming "Friday at the Lantern Room" isn't consent to "Saturday in a backyard". The host can't decline its own slot (it cancels instead). Pending and declined acts are never named, even in the generated title.
- **Cancel vs. take down:** hosts *cancel* (`cancelled_at`) — the page stays up with a banner, because people holding the link need to know, and the show leaves every listing. Moderators *take down* (`remove_show`, soft delete) — the page 404s. An archived host's shows leave the listings too.
- **"Upcoming"** = starts later, or started within `riff.shows.still_on_hours` (4h) — tonight's show doesn't vanish when the doors open. Past shows keep their page with a "has already happened" banner.
- **Going / interested** (`show_rsvps`): private by default; counts are public, names only for fans who opt in. Not for guests (register prompt with `intent=fan`), cancelled, or past shows. `follows` limiter.
- **Surfaces:** public show page `GET /shows/{id}` (OpenGraph meta, ticket link, age policy falling back to the venue's, "listed by"); upcoming shows on artist pages (with "List a show" for members) and venue pages; in the Artist Zone, a Shows card with pending invitations (confirm/decline) and the user's hosted shows, plus a per-artist "List a show" button. Lineup and venue pickers use small authenticated lookups (`/search/artists`, `/search/venues`) rather than the map search, because an artist hidden from the map still plays shows.
- `show` joins `ReportStoreRequest::SUBJECTS`; the moderation queue summarises a show by bill, time, place, and host. `shows` limiter: 5/minute, 30/day.
- Not yet: shows on the map (Step 4), the "this spot keeps hosting — make it a DIY venue?" nudge (deferred; nothing automates it), notifications to an artist when they're added to a bill or re-asked after a change (Phase 3 notifications — for now the invite waits in the Artist Zone), un-cancelling (list it again), and the blocked-lineup rule (lands with the map in Step 4).

**Done when:** a band lists Friday's show with another Riff band on the bill, the second band confirms from their zone, both artist pages and the venue page show it, and a fan can mark "going" without their name appearing. ✅

## Step 4 — One scene map: Shows and Venues join Artists — ✅ BUILT (November 2026)

No switchable layers (revised on review): artists, shows, and venues render **together by default**, and kind toggles sit in the filter bar beside genre, so hiding venues is just another filter. One `/map` URL carries kinds, filters, and the date window (`?kinds=shows,venues&when=weekend&genres=punk`), and a URL carrying any of them suppresses the welcome overlay like any other filter.

**What was built**
- `GET /map/shows` and `GET /map/venues` (public, `map` limiter) beside `/map/artists`. Each kind keeps its own endpoint, query and resource; the client fetches the enabled kinds in parallel. The "where" parsing is shared by all three (`ParsesMapArea` trait, `Geo::confine`), so a viewport or radius means the same thing everywhere.
- **Shows** (`SceneMapSearch::shows`): listed shows in the area within a **date window** — tonight, this weekend, next 7 days, next 30 days (default) — computed in the *viewer's* timezone (the client sends `tz`; "this weekend" before Friday starts on Friday), opening 4 hours back so tonight's show stays on after doors. A show is placed at its venue, or at its own town-level point if it's one-off; a show at a taken-down venue has no point and leaves the map. Ordered soonest first, nearest within a day.
- **Venues** (`SceneMapSearch::venues`): `show_on_map`, geolocated, in the area. **Dormant venues fade:** no non-cancelled show in `riff.map.venue_dormant_days` (180) and not added within that window → off the map, page stays. The "newly added" grace was added here — without it a venue would be invisible until its first show was listed.
- **Genre filter carries over:** a show matches if a *confirmed* act on the bill has the genre (a pending invite doesn't make a bill punk); a venue matches if it has such a show coming up — the genre lives on the acts, not the room. The artist-only filters (type, has music/photo, followed, sort) don't apply to shows or venues, and a name search stays artists-only — shows and venues follow the viewport around it.
- **Blocking:** a show is hidden from a blocking viewer only when every Riff act on the confirmed bill is blocked (free-text acts don't count); such viewers bypass the cache, like the artists' `hiddenFor`.
- **UI:** shows get a calendar-leaf pin with the day of the month (amber), venues a storefront pin (teal), artists keep theirs (red). Each kind has its **own cluster group**, tinted to match, so a count always means one kind; venues draw lowest and shows highest, so tonight's show sits on top of its venue. The list has Shows / Artists / Venues sections; picking a show or venue opens a card built from its pin (no extra request) with a link to its page.
- **Demo data:** `SceneSeeder` (run by `DatabaseSeeder` after the pilot) adds seven invented venues around Greater Hartford — including a private DIY space and a dormant one that has faded — and a month of shows on the pilot's acts relative to the seeding date: tonight, the weekend, a backyard and a park show, a pending lineup slot, an in-store, a cancellation. Idempotent; not yet the full Step 7 pilot data.

**Deviations from the original plan**
- **One cache version, not one per kind.** A show pin depends on its artists (names, genres) and its venue, so per-kind versions would have to be bumped across kinds anyway. Show, Venue, and LineupEntry events and `GeocodeAddress` all bump `MapCache`.
- **No server-side grid clusters for shows and venues.** Both return a capped (`riff.map.max_scene_markers`, 300), nearest-first list that the browser clusters. Benchmarked on 2,007 venues and 20,010 shows (SQLite, uncached): town views 5–17 ms; zoomed out across the region, shows tonight 14 ms, venues 64 ms, and shows this month 168 ms with the cap hit (~3,300 matched). That last case is the trigger for adding server clustering for shows if real density approaches it. When the cap bites, the map shows the *soonest* 300 shows.
- **Fixed along the way:** PDO binds floats as strings, and SQLite never converts a string compared against an expression — so `coalesce(venue, show)` comparisons matched nothing, and the existing artist radius filter had silently been only its bounding box on SQLite. Bound values compared to expressions are now forced numeric (`? * 1.0`, portable to MySQL/Postgres), with a regression test that fails without the fix.

**Not yet**
- Hover-highlighting between list rows and show/venue pins (artists have it).
- A venue-kind filter (club, bar, DIY…).
- Searching venues or shows by name.
- Not checked in a real browser: headless Chromium couldn't start in the dev environment (missing system libraries), so the UI is verified by build and API tests only.

**Done when:** opening the map shows artists, this week's shows, and venues together; narrowing to "shows this weekend" is two filter taps whose URL is shareable; and the private DIY space renders at its town, never its door. ✅ (pending a visual pass in a browser)

## Step 5 — Scene views

- Genre **families** in `config/riff.php`: `families` mapping a family slug to curated genre names (`punk` ⊃ Punk, Hardcore, Emo, Ska…), reconciled onto genre ids via `GenreCatalog`. A genre may sit in more than one family.
- **Places:** a small `places` table (`name`, `slug` like `hartford-ct`, `lat`/`lng`, `radius_km` default from config) seeded from distinct artist hometowns' stored coordinates (a `riff:sync-places` idempotent command, run by the seeder and schedulable). No request-path geocoding; a place with no coordinates simply has no scene page yet. This also upgrades `GET /map/places` to read the table instead of aggregating per request.
- `GET /scenes/{place:slug}/{family}` (public, `map` limiter, cacheable for guests under the layer cache versions): one page composing that area + genre set — artists (nearest first), upcoming shows, venues hosting them, and (after Step 6) active listings. Radius-based, not town-boundary-based. An all-genres variant `GET /scenes/{place:slug}` lists the families that have artists there.
- The page is server-rendered Inertia (not the Leaflet map) with a small static map strip linking into the full map pre-filtered (`/map?view=shows&genres=punk&…` — the URL-state work from Step 4 makes this free).
- Share meta so a scene link unfurls as "Hartford punk on Riff — 12 artists, 3 shows this month".
- Tests: family resolution, radius composition, cache behaviour, empty-scene state (invites artists, like the empty map).

**Done when:** `/scenes/hartford-ct/punk` is a link someone can text a friend, and it answers "who plays, what's on, where" without sign-in.

## Step 6 — Listings ("looking for")

- Migration `listings`: `type` (`members_wanted` / `band_wanted` / `bill_wanted` / `practice_space`), `user_id`, `artist_id` (nullable — required for `members_wanted`/`bill_wanted`, posted *as* the artist; null for seeker types), `instruments` (JSON list from a config vocabulary), `body` (bounded), `hometown`/`stateOrProvince` + `lat`/`lng` (defaulted from the artist or user, approximate — reuse `Geocodable`), `expires_at` (default +30 days, max +60, bump-able once), soft deletes. Genres via a `listing_genre` pivot mirroring `artist_genre`.
- `ListingPolicy`: creating as an artist requires membership; owner edits/closes/bumps. `listing` joins report SUBJECTS and `ModerationAction` (remove-listing, recoverable). `listings` limiter (tight — this is a spam magnet).
- **Replies are relayed email, interim by design:** `POST /listings/{listing}/replies` (verified users, very tight limiter, one reply per user per listing) takes a message plus optional contact handle, and queues a Mailable to the poster. The replier's email is never exposed unless they typed it; the poster's is never exposed at all. Store replies in a `listing_replies` table (needed for the one-reply rule, abuse review, and so the thread can migrate into the Phase 4 **in-app messaging system** that will replace this relay).
- Surfaces: a listings section on scene views, a browse page `GET /listings` scoped to an area + filters (type, instrument, genre), active listings on the posting artist's page, and the artist-create "join" stub finally pointed at real `band_wanted`/`members_wanted` listings instead of the fake search. Map layer only if the pilot asks — a list scoped by area covers the need without another index.
- Expiry is a query concern (`active()` scope), not a cron: expired listings vanish from every surface and the owner can renew.
- Tests: policy (member-only artist listings), expiry, relay (via `Mail::fake`), one-reply rule, report round-trip, limiter.

**Done when:** a band posts "drummer wanted — Hartford, punk/hardcore", it shows in the scene view, a drummer replies, and the band gets an email relay with no addresses leaked either way.

## Step 7 — Pilot seed data + docs

- Extend `PilotSeeder` (idempotent, as now, and re-run by CI): ~6 invented venues including one private DIY space (no street stored), plus a backyard show with a one-off location to show the difference, ~10 shows across the next six weeks with shared bills (including one pending lineup confirmation and one imported-source show to demo the label), RSVPs from the seeded listeners, 3–4 listings (one near expiry), venue/show/listing entries in the report queue, and places synced so `/scenes/hartford-ct/punk` works out of the box.
- Update README (setup + feature list), CLAUDE.md (new models, policies, map layers, cache keys, gotchas discovered along the way), and mark Phase 2 progress in ROADMAP.

**Done when:** `php artisan migrate:fresh --seed` demos the entire Phase 2 loop, and CI's seed-twice check still passes on all three drivers.

## Step 8 — Imported events (optional, last)

- A queued `riff:import-events` command maps `EventProvider` results into `shows` rows (`source = 'imported'`, deduped on `provider`+`provider_ref`, venue matched by name+town or left as `location_note`). Labelled everywhere, never outranking member shows, degrades to a no-op when the provider is down. Ticketmaster only where it adds local coverage; the contract stays open for venue-calendar providers.
- This step is genuinely optional for the pilot — a real scene's shows should come from Step 3. Build it only if seeded coverage looks thin when recruiting starts.

---

## Decisions already made (flag now if you disagree)

1. ~~Slugs are permanent~~ **Revised on review: links are permanent, slugs follow the name.** A rename mints a new slug; old slugs and old numeric id URLs 301-redirect forever, and a parked slug is never given to another artist.
2. **Venues publish immediately** and are moderated reactively (report → hide), because pre-moderation would block show submission on moderator latency.
3. ~~Switchable map layers~~ **Revised on review: one unified scene map.** All kinds render together by default with filter-bar toggles and a single shareable `/map` URL; the data endpoints stay separate per kind (`/map/shows`, `/map/venues`) for index, cache, and request-shape reasons.
4. **Places are a table** synced from stored coordinates by an idempotent command — never geocoded at request time.
5. ~~Address privacy is rounding~~ **Revised in Step 2: privacy by not storing.** Venues are public (street stored, pinned at the door) or private (no street stored, pinned at the town). One-off show locations are always town-level with no street. Nothing needs rounding because nothing precise is held.
8. **Venues are recurring places; one-off locations belong to shows** (from Step 2 review). Houses aren't a venue kind; a named DIY space with a running calendar is.
9. **Dormant venues fade from the map** (Step 4): a venue with no upcoming show and none in the last ~6 months drops off the default map view while its page stays — the same "nothing lingers" rule that keeps one-off locations off the map after the night.
6. **Listing replies store a row** even though delivery is email, so the one-reply rule works and threads can migrate to in-app messaging in Phase 4.
7. **A show is hidden from a blocking viewer only when every Riff artist on the lineup is blocked.**

## Suggested checkpoints

Steps 1–4 are built; Step 5 (scene views) is next. Steps 4–5 land the "open Riff, see the scene" promise. Step 6 completes the feature set; 7 makes it demoable; 8 only if needed.
