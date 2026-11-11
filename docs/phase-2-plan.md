# Phase 2 implementation plan — put the whole scene on the map

Status: **approved** (November 11, 2026), with revisions from review: slugs follow renames (old links redirect) rather than being frozen, and the map is one unified scene view rather than switchable layers; venues are recurring places and one-off locations belong to shows. **Steps 1–2 are built.**

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

## Step 3 — Shows

- **Where a show happens is one of two things, never a throwaway venue row** (revised with Step 2): either a `venue_id`, or the show's **own one-off location** — a label (`location_name`, e.g. "Backyard on the west side"), a town (`city`/`state`/`country`, geocoded at town level only, like artists), and an optional `location_note` ("ask a punk", "DM for the address", TBA). No street is ever stored for a one-off location. The show's pin exists only while the show is upcoming, so a backyard never becomes a permanent map fixture. If the same informal spot keeps hosting, that's the signal to add it as a (private) DIY venue — the show form can suggest it, nothing automates it.
- Migration `shows`: `title` (nullable — default render is the lineup), `venue_id` (nullable FK), the one-off location columns above (null when `venue_id` is set; exactly one of the two is required), `starts_at`, `doors_at` (nullable), `price` (string, nullable), `ticket_url` (nullable, validated http(s)), `age_policy` (nullable — defaults from venue), `source` (`member` / `imported`), `provider`/`provider_ref` (nullable, for Step 8 dedupe), `created_by`, soft deletes.
- Migration `show_lineup`: `show_id`, `artist_id` (nullable), `name` (free text for acts not on Riff; exactly one of `artist_id`/`name`), `position`, `status` (`confirmed` / `pending` / `declined`). When a member creates a show, entries for artists they belong to start `confirmed`; entries naming other Riff artists start `pending` and surface in that artist's zone to confirm or decline — one band cannot put another on a bill without consent. Declined entries drop to free-text display or vanish (creator's choice on decline). Free-text names need no confirmation.
- Migration `show_rsvps`: `show_id`, `user_id`, `status` (`going` / `interested`), `is_public` (default false), unique per user+show. Public counts only; names shown only for `is_public` users.
- `ShowPolicy`: create requires membership in at least one artist on the lineup; update/cancel for the creator and members of confirmed lineup artists; cancel is soft delete. Lineup confirm/decline authorizes via membership in *that* entry's artist. RSVP for any verified user (`follows`-style limiter).
- Surfaces: show page `GET /shows/{show}` (public), upcoming shows on artist pages and venue pages (`whereHas` on confirmed lineup / venue, `starts_at >= now()`, index on `(venue_id, starts_at)` and lineup pivot keys). `show` joins report SUBJECTS; `ModerationAction` gains remove-show (recoverable).
- Imported shows (`source = 'imported'`) render with a visible provider label and never above member shows in any list. Nothing imports yet — the column exists so Step 8 is additive.
- Pages: `pages/Shows/Show.vue`, `pages/Shows/Create.vue` (from the artist zone: "Add a show"), lineup-confirmation prompts in `ArtistZone/Index.vue`.
- Tests: creation + policy, lineup consent flow (pending → confirm/decline), RSVP privacy, venue/artist page listings, reporting, date handling across drivers.

**Done when:** a band lists Friday's show with another Riff band on the bill, the second band confirms from their zone, both artist pages and the venue page show it, and a fan can mark "going" without their name appearing.

## Step 4 — One scene map: Shows and Venues join Artists

Revised on review: no switchable layers. Artists, shows, and venues render **together by default** — opening Riff shows the whole scene with nothing to know about — and kind toggles join the existing filter bar, so hiding venues is a filter like hiding a genre. One `/map` URL carries kinds + filters + date window, all shareable.

- Endpoints: `GET /map/shows` and `GET /map/venues`, public, under the `map` limiter, alongside `/map/artists` — the *data* stays per-kind (own request class, resource, covering index, cache shape) even though the *view* is unified; the client fetches enabled kinds in parallel and composes them.
- Extract the reusable core of `ArtistMapSearch` (probe-for-cap, grid clustering, expanding-circle nearest list, equirectangular ordering) into a shared base or trait; `VenueMapSearch` and `ShowMapSearch` implement `matching()` for their filters. Each table gets its own covering index: `venues_map_index (show_on_map, deleted_at, lat, lng)`, and shows query through venue/lineup joins bounded by `starts_at` — benchmark that join against a large seeded dataset before shipping, per the existing rule. Venue pins come from `VenueResource` (a private venue's point is already only its town). Dormant venues (no upcoming show, none in ~6 months) drop off the default view; their pages stay. Markers cluster **per kind** (separate cluster groups, distinct pin styles) so a cluster count always means one thing; a show at a venue sits on the venue's pin position and the existing same-coordinate spiderfy handles the overlap.
- Show filters: date window (`tonight`, `weekend`, `week`, `month`, or explicit range), plus genre (via lineup artists' genres) and age policy. Venue filters: kind. Caching mirrors `MapCache`: per-layer version keys bumped by Show/Venue model events (`MapCache` grows `version(string $layer)` rather than a second helper class).
- UI: `useMapFilters` gains a `kinds` set (artists, shows, venues — all on by default) mirrored to the URL like everything else (`replaceState`); FilterBar gets the kind toggles and a date-window control that applies to shows; `markers.js` gets show/venue pin + cluster variants (same `textContent` discipline); `ResultList` and the card panel render mixed, per-kind cards (show card: date, lineup, venue, price; venue card: kind, area, next show), grouped or badged by kind so the list stays scannable. `useMapData` fans out one request per enabled kind and merges results; an aborted kind is not an error state. Blocked artists' shows: a show is hidden for a viewer only if *every* Riff artist on its lineup is blocked — same `hiddenFor` personal-cache rule.
- Guest CTAs stay intact: the kind toggles must not break the welcome-overlay suppression rules (a URL carrying non-default kinds counts as “carries filters”).
- Tests: bounds/radius parity with the artists queries, date windows across drivers and timezones, per-kind cluster counts, cache rotation on show/venue changes, blocked-lineup behaviour, kind toggles in the URL.

**Done when:** opening the map shows artists, this week's shows, and venues together; narrowing to "shows this weekend" is two filter taps whose URL is shareable; and the private DIY space renders at its town, never its door.

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

Steps 1–2 are built; Step 3 (shows, including one-off locations) is the next review cycle. Step 3 is the biggest single step and worth its own review. Steps 4–5 land the "open Riff, see the scene" promise. Step 6 completes the feature set; 7 makes it demoable; 8 only if needed.
