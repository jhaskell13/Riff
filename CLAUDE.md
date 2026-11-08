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

**Request flow:** routes/web.php → controller → Inertia::render('Dir/Page') → `resources/js/pages/Dir/Page.vue` (lowercase `pages/` — Inertia v3 convention; `Components/` and `Layouts/` are capitalized). Everything except `/`, `/health`, and auth routes sits behind `['auth', 'verified']` — User implements `MustVerifyEmail`, so verification is enforced for real.

**Authorization is policy-only.** `ArtistPolicy` and `LiveStreamPolicy` (auto-discovered) gate every state-changing route via `$this->authorize()` in controllers; the base `Controller` has `AuthorizesRequests`. FormRequests validate only (`authorize()` returns true). Artist membership = `User::isMemberOf()` (an `exists()` query). "Delete" of an artist is a soft-delete archive — shared identities must be recoverable.

**Geocoding pipeline (the part that spans files):** `User` and `Artist` implement `App\Contracts\Geocodable` and are observed by `GeocodableObserver` (registered via `#[ObservedBy]` on the models). Creating a record without coords, or changing address fields, queues `App\Jobs\GeocodeAddress`, which calls `App\Services\Geocoder` and writes lat/lng via `saveQuietly()` (so observers don't loop). Geocoder returns `?MapPoint` — null means "couldn't geocode"; callers must treat null as "off the map", never substitute fake coordinates. Nothing in a request path ever geocodes.

**Map:** `MapController@index` renders the shell with a center (user coords or `config('riff.map.default_center')`). The Vue page fetches `GET /map/artists?south&north&west&east` on moveend/zoomend (debounced, AbortController-cancelled); the endpoint validates bounds, uses the `geolocated()`/`withinBounds()` scopes on Artist, caps results at `riff.map.max_markers`, and returns `ArtistMarkerResource`. Leaflet popups are built with DOM nodes (`textContent`), never HTML strings — artist names are user input.

**Events:** `App\Contracts\EventProvider` → `App\Services\Events\TicketmasterEvents` (bound in AppServiceProvider), returning provider-agnostic `App\DTO\LocalEvent`s. Not wired to the UI yet; new sources implement the contract.

**Known prototype (do not polish):** livestreaming transport — HTTP POSTed base64 chunks re-broadcast over *public* channels — is a dead end slated for a managed provider in Phase 4. Its endpoints are policy-protected; leave the transport alone. Feed like/comment schema exists but UI is hidden until Phase 3.

## Conventions and gotchas

- Persist only `$request->validated()`; create user-owned rows through relations (`$request->user()->posts()->create(...)`) so ownership can't be mass-assigned. MassAssignmentTest enforces this.
- Tests must be environment-independent: external HTTP via `Http::fake()`, broadcasts via `Event::fake()`/pinned null driver. phpunit.xml pins `BROADCAST_CONNECTION`, empty `GOOGLE_MAPS_API_KEY`/`TICKETMASTER_API_KEY`; add a pin there when introducing any env-dependent service.
- Column naming is inconsistent by history: artists use `hometown`/`stateOrProvince`, users use `city`/`state`. `Geocodable::geocodableAddress()` papers over this.
- Feed posting is rate-limited via the named `posts` limiter (AppServiceProvider). Feed JSON goes through `PostResource` — extend the resource, don't serialize models raw.
- Like relations are named `likers()` (Post, Comment): the tables carry integer `likes` counter columns, and a relation named `likes` would be shadowed by the column.
- Archived artists can be restored: `artists.restore` route uses `->withTrashed()` binding and `ArtistPolicy::restore` (membership checked with trashed included). Coordinates are nulled the moment an address changes (observer `updating`) — stale map positions are a bug.
- CI (`.github/workflows/ci.yml`): pint --test, npm build, PHPUnit; deploy.yml requires it before CodeDeploy to EC2 on pushes to main. The CodeDeploy script builds production `.env` from `.env.example` + Secrets Manager and currently regenerates APP_KEY every deploy (known issue).
- Tailwind 4 is CSS-first: design tokens live in `@theme` inside resources/css/app.css (no tailwind.config.js); plugins load via `@plugin`, extra scan paths via `@source`. The `@tailwindcss/vite` plugin replaces PostCSS.
- Quasar's vite plugin runs with `astAutoImport: false` (see vite.config.js) because its AST auto-import double-registers under Vite 8 and breaks the build with duplicate imports; revisit when the plugin supports Vite 8 cleanly.
- leaflet-providers v4 uses a single `Jawg` provider with a `variant` option (`jawg-dark`), not `Jawg.Dark`.
- Do not commit or push unless asked; the owner handles remotes.
