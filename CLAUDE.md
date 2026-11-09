# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Riff is a local music discovery platform (Laravel 13, Inertia v3, Vue 3, Quasar + Tailwind 4, Vite 8, Leaflet). The map of nearby artists is the core feature. Users hold multiple "artist identities" (band/solo/producer) via the `artist_user` pivot. See ROADMAP.md for phased product direction — Phase 0 (dependable prototype) is complete; Phase 1 (local discovery MVP) is next.

## Commands

```bash
composer run dev          # serve + queue listener + pail logs + vite, concurrently
composer test             # config:clear + full PHPUnit suite (offline; all HTTP faked)
php artisan test --filter test_name_or_class   # single test
./vendor/bin/pint         # format PHP (CI enforces pint --test)
npm run build             # Vite build (client + SSR); REQUIRED before php artisan test
                          # if you added/renamed a page component (manifest check)
php artisan migrate:fresh --seed   # rebuild DB with 100 artists, 30 users, 40 posts
php artisan riff:geocode-missing   # queue geocoding for records without lat/lng
```

Local default is SQLite; queue/cache/session use the database drivers. Test user from the seeder: `test@example.com` / `password`.

## Architecture

**Request flow:** routes/web.php → controller → Inertia::render('Dir/Page') → `resources/js/pages/Dir/Page.vue` (lowercase `pages/` — Inertia v3 convention; `Components/` and `Layouts/` are capitalized). Everything except `/`, `/health`, auth routes, and the public map data endpoints (`map/artists`, `map/artists/{artist}`, `map/places`) sits behind `['auth', 'verified']` — User implements `MustVerifyEmail`, so verification is enforced for real.

**Authorization is policy-only.** `ArtistPolicy` and `LiveStreamPolicy` (auto-discovered) gate every state-changing route via `$this->authorize()` in controllers; the base `Controller` has `AuthorizesRequests`. FormRequests validate only (`authorize()` returns true). Artist membership = `User::isMemberOf()` (an `exists()` query). "Delete" of an artist is a soft-delete archive — shared identities must be recoverable.

**Geocoding pipeline (the part that spans files):** `User` and `Artist` implement `App\Contracts\Geocodable` and are observed by `GeocodableObserver` (registered via `#[ObservedBy]` on the models). Creating a record without coords, or changing address fields, queues `App\Jobs\GeocodeAddress`, which calls `App\Services\Geocoder` and writes lat/lng via `saveQuietly()` (so observers don't loop). Geocoder returns `?MapPoint` — null means "couldn't geocode"; callers must treat null as "off the map", never substitute fake coordinates. Nothing in a request path ever geocodes.

**Map:** `MapController@index` renders the shell (still behind auth) with a center and `filterOptions`. The data endpoints are **public** and rate-limited (`map` limiter): `GET /map/artists`, `GET /map/artists/{artist}` (card), `GET /map/places?q=` (towns that have artists, using stored coordinates — never geocodes). `MapArtistsRequest` validates and normalises parameters into an `App\DTO\MapFilters` (bounds snapped outward via `Geo::snapBounds`, origin rounded to 3 dp, lists sorted, genre slugs → ids via the cached `GenreCatalog`); a request must give a viewport, a radius (`lat`/`lng`/`radius`/`unit`), or `q`. `App\Services\Map\ArtistMapSearch` does the work:
- Probes for row `cap+1` (not `COUNT`) to choose the shape. At or under the cap → all markers, nearest first. Over it → server-side grid clusters (`GROUP BY` cell, which also yields the total) plus the nearest `list_limit` via an expanding circle (exact: anything outside a circle holding ≥N is farther). Search-only (`q`, no area) is global and capped at `search_limit`.
- Distance in SQL is an arithmetic-only equirectangular expression (portable, orderable); display distance is haversine in PHP. Text search unions the name/hometown scan with a pivot lookup for matching genres — `OR` there was ~7× slower.
- Everything runs off the covering `artists_map_index (show_on_map, deleted_at, lat, lng)`. Keep new filters index-friendly, and benchmark against a large dataset before adding anything that reads table rows per match.
- Non-personal results are cached under `MapCache::version()`, rotated by Artist `saved/deleted/restored` and by `GeocodeAddress` (which writes quietly). Anything that changes what the map shows without a model event must call `MapCache::bump()` — or `touch()` the artist, as genre-only edits do.

Leaflet popups are built with DOM nodes (`textContent`), never HTML strings — artist names are user input.

**Events:** `App\Contracts\EventProvider` → `App\Services\Events\TicketmasterEvents` (bound in AppServiceProvider), returning provider-agnostic `App\DTO\LocalEvent`s. Not wired to the UI yet; new sources implement the contract.

**Known prototype (do not polish):** livestreaming transport — HTTP POSTed base64 chunks re-broadcast over *public* channels — is a dead end slated for a managed provider in Phase 4. Its endpoints are policy-protected; leave the transport alone. Feed like/comment schema exists but UI is hidden until Phase 3.

## Conventions and gotchas

- Persist only `$request->validated()`; create user-owned rows through relations (`$request->user()->posts()->create(...)`) so ownership can't be mass-assigned. MassAssignmentTest enforces this.
- Tests must be environment-independent: external HTTP via `Http::fake()`, broadcasts via `Event::fake()`/pinned null driver. phpunit.xml pins `BROADCAST_CONNECTION`, empty `GOOGLE_MAPS_API_KEY`/`TICKETMASTER_API_KEY`; add a pin there when introducing any env-dependent service.
- Column naming is inconsistent by history: artists use `hometown`/`stateOrProvince`, users use `city`/`state`. `Geocodable::geocodableAddress()` papers over this.
- Feed posting is rate-limited via the named `posts` limiter (AppServiceProvider); following uses the `follows` limiter. Feed JSON goes through `PostResource` — extend the resource, don't serialize models raw.
- **Genres are a pivot, not a column.** `artists.genre` is gone; use the `genres`/`artist_genre` tables via `Artist::genres()`. The curated vocabulary lives in `config('riff.genres')` and is reconciled by `GenreSeeder` (idempotent). Values that aren't curated are flagged `is_curated = false` rather than dropped.
- **Playable music:** `artists.music_url` is set through an Eloquent `Attribute` that runs `App\Services\MusicLink::parse()`, storing the canonical URL and deriving `music_provider`. `music_provider` is not fillable. Embed URLs are rebuilt on read (`$artist->music_embed`) from fixed per-provider templates — never interpolate a user-supplied URL into an iframe `src`. Unrecognised links are rejected by `App\Rules\PlayableMusicUrl`.
- **Artist photos:** `artists.image_path` (not fillable; written only by the upload pipeline) on the `config('riff.media.disk')` disk; `image_url` is a read-only accessor that replaced the old column. Uploads must be re-encoded to strip EXIF — a phone photo's GPS tags would leak the location the public-area rule exists to protect. Requires the `gd` extension.
- **Follows:** `artist_follows` pivot via `Artist::followers()` / `User::followedArtists()`. Counts come from `withCount('followers')`; deliberately no `followers_count` column, which would shadow the relation (same trap as `likes`).
- `show_on_map` lets an artist keep a public profile without a pin; every map query must chain the `visibleOnMap()` scope alongside `geolocated()`.
- Distances render in `users.distance_unit` ('mi'/'km'), guessed at registration by `User::distanceUnitForCountry()`.
- Like relations are named `likers()` (Post, Comment): the tables carry integer `likes` counter columns, and a relation named `likes` would be shadowed by the column.
- Archived artists can be restored: `artists.restore` route uses `->withTrashed()` binding and `ArtistPolicy::restore` (membership checked with trashed included). Coordinates are nulled the moment an address changes (observer `updating`) — stale map positions are a bug.
- CI (`.github/workflows/ci.yml`): pint --test, npm build, PHPUnit; deploy.yml requires it before CodeDeploy to EC2 on pushes to main. The CodeDeploy script builds production `.env` from `.env.example` + Secrets Manager and currently regenerates APP_KEY every deploy (known issue).
- Tailwind 4 is CSS-first: design tokens live in `@theme` inside resources/css/app.css (no tailwind.config.js); plugins load via `@plugin`, extra scan paths via `@source`. The `@tailwindcss/vite` plugin replaces PostCSS.
- Quasar's vite plugin runs with `astAutoImport: false` (see vite.config.js) because its AST auto-import double-registers under Vite 8 and breaks the build with duplicate imports; revisit when the plugin supports Vite 8 cleanly.
- leaflet-providers v4 uses a single `Jawg` provider with a `variant` option (`jawg-dark`), not `Jawg.Dark`.
- Do not commit or push unless asked; the owner handles remotes.
