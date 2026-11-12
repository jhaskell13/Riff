# Riff

Riff is a local music scene app. Open it in a town and see the scene around you: the bands and artists who play there, the shows coming up, the venues and DIY spaces that host them, and the acts looking for new members. Find an artist you love, become a fan, and their page becomes the place to hear from them, talk with other fans, and support them by buying their music and merch.

Riff is deliberately *not* a general social feed or a streaming service. Everything in it is anchored to a place, an artist, a show, or a venue. The [roadmap](ROADMAP.md) sets out the identity, principles, and phased plan; longer-term ideas include listening parties and intimate live sessions for fans.

## Project status

Riff is a prototype under active development, not a finished public service.

**Phase 0 (dependable prototype) is complete:** authentication with enforced email verification, policy-protected artist identities with a full lifecycle, a server-backed feed, and a viewport-driven artist map with persisted geocoded coordinates.

**Phase 1 (local discovery MVP) is complete:** artist profiles with genres, photos, and playable music; a public map with artist cards, a synchronised list, search, filters, and clustering; onboarding that splits a first-time visitor into listener and artist paths; reporting, per-listener blocking, and a moderator report queue; and a seeded pilot scene to demonstrate it with.

**Phase 2 (put the whole scene on the map) is next:** public artist pages, venues, shows with multi-artist lineups, one map that shows artists, shows, and venues together with kind and date filters, shareable scene views (a place plus a genre family, like "Hartford punk"), and "looking for" listings. Phase 3 then turns the artist page into a home for fans. See the [roadmap](ROADMAP.md).

The current global feed and the livestream prototype predate this direction. The feed is replaced in Phase 3, and the livestream transport is retired rather than extended. The Ticketmaster event lookup exists but is not wired into the UI.

## Stack

- Laravel 13 and PHP 8.3+
- Vue 3, Inertia.js, and Vite
- Leaflet with marker clustering for the map
- SQLite by default for local development; Laravel supports other configured database connections
- Optional Google Maps geocoding, Ticketmaster event lookup, and Laravel broadcasting integrations

## Requirements

- PHP 8.3 or newer with the extensions required by Laravel
- Composer
- Node.js and npm
- A database supported by Laravel (SQLite is the default)

## Local setup

From the repository root:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use `Copy-Item .env.example .env` instead of `cp`.

Update `.env` for your machine. At minimum, set `APP_URL=http://127.0.0.1:8000`. The example uses SQLite; make sure the configured database file exists, then run migrations:

```bash
php artisan migrate
```

To load the development sample data:

```bash
php artisan storage:link   # once, so seeded artist photos resolve
php artisan db:seed
```

The seeder builds a demonstrable dataset from `database/seeders/data/pilot-scene.json`, a researched snapshot of the real Connecticut scene: about 20 acts (real ones like Boundaries, Anxious, Cheem and The Meadows Brothers alongside a handful of invented ones), ten real venues from The Webster to The Buttonwood Tree at their public addresses, a month of composed shows dated from whenever you seed, listings, listeners, posts, and a report queue — plus around 75 invented artists scattered across Connecticut, Massachusetts, Rhode Island, and the Hudson Valley so the map stays populated as you pan. Sign in as `test@example.com` / `password`; that account is also the local moderator, so the report queue under **Reports** in the account menu is reachable.

This data is for local development and demos **only** — the real acts and venues in it never agreed to be on Riff, so never seed it into a production database, and never import real artists who have not asked to be listed. The dataset's `_meta` block records its sources and its consent rules: everything *attributed* to its subject (reports, artist-voice posts, listings, pending lineup slots, cancellations, hidden/archived states, placeholder music) sits on the fictional entries only, real acts carry only music links verified during research, and the private DIY space is fictional on purpose. `PilotDatasetTest` enforces those rules in CI.

To add just the pilot scene to a database that already has accounts you want to keep:

```bash
php artisan riff:seed-pilot
```

It is idempotent, so running it twice changes nothing, and it refuses to run in production unless forced.

The map needs no API keys to work, but without `VITE_LEAFLET_JAWG_PROVIDER_KEY` it renders pins over a blank background instead of map tiles (see [Configuration](#configuration)).

Start the development processes:

```bash
composer run dev
```

This runs the Laravel server, queue listener, log viewer, and Vite dev server. Open the local URL printed by `php artisan serve` (usually `http://127.0.0.1:8000`). To run processes separately, use `php artisan serve` and `npm run dev`; the composer script also starts the queue listener and log viewer.

For a production asset build:

```bash
npm run build
```

## Configuration

Most local use does not require third-party API credentials. Optional integrations read these environment variables:

| Variable | Used for | Notes |
| --- | --- | --- |
| `GOOGLE_MAPS_API_KEY` | Geocoding artist and listener-entered city/region locations | Configure an appropriately restricted key and enable the required Geocoding API. Coordinates are geocoded once when an address is saved (queued job) and stored on the record; run `php artisan riff:geocode-missing` to backfill records created before a key was configured. Without a key, records simply stay off the map. |
| `TICKETMASTER_API_KEY` | Ticketmaster event lookup service | Event lookup exists in the backend but is not currently wired into the map UI. |
| `VITE_LEAFLET_JAWG_PROVIDER_KEY` | Jawg map tile layer in the map page | Exposed to browser code by Vite. A valid provider key is required for the configured Jawg layer. |
| `BROADCAST_CONNECTION` | Laravel event broadcasting | Defaults to `log` in the example environment. Live streaming experiments need a configured real-time broadcaster such as Reverb or Pusher to deliver events. |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Reverb broadcaster | Set when using Reverb; see Laravel Reverb configuration. |
| `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` | Pusher broadcaster | Set when using Pusher. |

Never commit real credentials. Keep secret values in your local or deployment environment configuration.

## Main areas of the code

- `app/Models` — users, artists, venues, shows and their lineups, genres, posts, comments, reports, and livestream records.
- `app/Policies` — authorization for artists, venues, shows, lineup answers, streams, and the report queue; every state-changing route checks these.
- `app/Http/Controllers` — request handling for the map, artist identities, feeds, follows, blocks, reports, moderation, and profiles.
- `app/Http/Resources` — JSON shapes for client-fetched data such as map markers, artist cards, and the report queue.
- `app/Services`, `app/Contracts`, `app/DTO` — geocoding, map search, music-link parsing, photo processing, the pluggable event-provider contract, and shared value objects.
- `app/Support` — small enums and helpers shared across layers (onboarding intent, moderation actions, map cache, geo maths).
- `app/Jobs` and `app/Observers` — queued geocoding triggered by address changes.
- `app/Console/Commands` — `riff:geocode-missing`, `riff:moderator`, `riff:seed-pilot`, and `riff:sync-places` (scene pages for every town with artists; scheduled daily, so production needs `php artisan schedule:run` in cron).
- `config/riff.php` — map defaults and tuning, artist types, the curated genre vocabulary, media limits, distance units, and moderation settings.
- `database/migrations` — database schema.
- `database/seeders` — the pilot scene and the wider development dataset.
- `resources/js/pages` — Inertia/Vue screens: the map, artist zone, profile, moderation queue, the global feed (to be replaced in Phase 3), and the livestream prototype.
- `resources/js/Components/Map` — the map's own components (filter bar, result list, artist card, welcome overlay, marker builders).
- `routes/web.php` — web routes.
- `tests` — PHPUnit feature and unit tests (fully offline; external services are faked).
- `ROADMAP.md` — what Riff is, its product principles, current status, and phased milestones.
- `CLAUDE.md` — architecture and convention notes for AI-assisted development.

## Tests and code style

Run the PHPUnit suite with:

```bash
composer test
```

Run static analysis (Larastan/PHPStan, level 6):

```bash
composer analyse
```

Run everything CI's quality gate runs — formatting check, static analysis, and the suite:

```bash
composer check
```

Format PHP files with Laravel Pint:

```bash
./vendor/bin/pint
```

On Windows, run `vendor/bin/pint` from PowerShell if the Unix-style path is unavailable.

The suite is fully offline: external services are faked with `Http::fake()`. Keep new tests deterministic the same way.

Check formatting without changing files:

```bash
./vendor/bin/pint --test
```

## Continuous integration and deployment

`.github/workflows/ci.yml` runs two gates on every pull request and on pushes to non-`main` branches:

- **Quality** — Pint formatting check, PHPStan static analysis, `composer validate --strict`, and dependency advisories (`composer audit`, `npm audit` for runtime dependencies).
- **Tests** — the Vite production build plus the full PHPUnit suite on a database matrix: SQLite (the development default), MySQL 8.4, and PostgreSQL 16 as service containers. Each matrix row also migrates and seeds from scratch and re-runs the pilot seeder to prove it is idempotent. The matrix exists because the app may ship on MySQL or Postgres: SQLite silently accepts mistakes (unknown columns become string literals; enum constraints go unenforced) that are hard failures on the real drivers.

`.github/workflows/deploy.yml` requires CI, then builds a production bundle (no dev dependencies, tests, or VCS metadata), uploads it to S3 under a per-commit key (`releases/release-<sha>.zip`, so old bundles remain available for rollback), triggers AWS CodeDeploy, and waits for the deployment to succeed so a failed deploy fails the workflow. Deploys are serialised by a concurrency group. The install script in `code-deploy/scripts` builds the production `.env` from `.env.example` plus AWS Secrets Manager: database settings come from the secret, `APP_KEY` is preserved across deploys (set `app_key` in the secret, or the previous release's key is reused), `app_url` in the secret is required, and the script links storage, rebuilds caches, migrates, and restarts queue workers.

## Product direction

Riff exists to make local music scenes visible and to connect them: the artists, their shows, the places they play, and the fans who keep them going. The core loop is **find your scene → go to a show → become a fan → hear from and support the artist → come back for the next show.** Phases 0 and 1 built the first step, a public map of local artists with profiles, music previews, onboarding, and safety tooling. The next phases add shows, venues, scene views, and listings (Phase 2); artist pages as fan homes with walls, posts in the artist's own voice, support links, and notifications (Phase 3); and tools for venues, promoters, and band members (Phase 4). Listening parties and live sessions are long-term (Phase 5), gated on demonstrated demand. [ROADMAP.md](ROADMAP.md) has the full plan and the principles behind it.

## License

Riff is licensed under the [MIT License](LICENSE).
