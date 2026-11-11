# Riff

Riff is an experimental local music discovery app. It helps listeners find independent artists near a chosen area and gives bands, solo artists, rappers, producers, and other music makers an artist identity they can use to share their work.

The core idea is map-led discovery: explore local artists, hear their music, and find a reason to follow them or see them live. The project also contains early experiments with artist posts, events, and livestreaming. Those parts are still in progress; see the [development roadmap](ROADMAP.md).

## Project status

Riff is a prototype under active development, not a finished public service.

**Phase 0 (dependable prototype) is complete:** authentication with enforced email verification, policy-protected artist identities with a full lifecycle, a server-backed feed, and a viewport-driven artist map with persisted geocoded coordinates.

**Phase 1 (local discovery MVP) is complete:** artist profiles with genres, photos, and playable music; a public map with artist cards, a synchronised list, search, filters, and clustering; onboarding that splits a first-time visitor into listener and artist paths; reporting, per-listener blocking, and a moderator report queue; and a seeded pilot scene to demonstrate it with.

Livestreaming remains an early prototype and the Ticketmaster event lookup is not yet wired into the UI. Phase 2 — an artist-first follows feed, local events, and notifications — is next; see the [roadmap](ROADMAP.md).

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

The seeder builds a demonstrable dataset: a dense pilot scene of about 25 artists across Greater Hartford — several per town, with genres, bios, generated cover art, playable links on most of them, listeners following them, posts, and a report queue — plus around 75 more artists scattered across Connecticut, Massachusetts, Rhode Island, and the Hudson Valley so the map stays populated as you pan. Sign in as `test@example.com` / `password`; that account is also the local moderator, so the report queue under **Reports** in the account menu is reachable.

Every seeded artist is invented, and the music links on them are public placeholder tracks, not those acts' own work. This data is for local development and demos only — never seed it into a production database, and never import real artists who have not asked to be listed.

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

- `app/Models` — users, artists, genres, posts, comments, reports, and livestream records.
- `app/Policies` — authorization for artists, streams, and the report queue; every state-changing route checks these.
- `app/Http/Controllers` — request handling for the map, artist identities, feeds, follows, blocks, reports, moderation, and profiles.
- `app/Http/Resources` — JSON shapes for client-fetched data such as map markers, artist cards, and the report queue.
- `app/Services`, `app/Contracts`, `app/DTO` — geocoding, map search, music-link parsing, photo processing, the pluggable event-provider contract, and shared value objects.
- `app/Support` — small enums and helpers shared across layers (onboarding intent, moderation actions, map cache, geo maths).
- `app/Jobs` and `app/Observers` — queued geocoding triggered by address changes.
- `app/Console/Commands` — `riff:geocode-missing`, `riff:moderator`, and `riff:seed-pilot`.
- `config/riff.php` — map defaults and tuning, artist types, the curated genre vocabulary, media limits, distance units, and moderation settings.
- `database/migrations` — database schema.
- `database/seeders` — the pilot scene and the wider development dataset.
- `resources/js/pages` — Inertia/Vue screens: the map, artist zone, profile, moderation queue, and the experimental feed/stream pages.
- `resources/js/Components/Map` — the map's own components (filter bar, result list, artist card, welcome overlay, marker builders).
- `routes/web.php` — web routes.
- `tests` — PHPUnit feature and unit tests (fully offline; external services are faked).
- `ROADMAP.md` — product direction, current status, and milestones.
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

Riff is focused on helping people discover artists who may not appear in their usual streaming recommendations. With Phase 1 complete, the map, artist profiles, music previews, onboarding, and the safety tooling needed before public growth are all in place and demonstrable against the seeded pilot scene. The next step is Phase 2: turning discovery into participation with an artist-first follows feed, local events, and notifications, shaped by what pilot listeners and artists actually use. Real-time livestreaming and remote live jams remain exploratory because they add significant reliability, moderation, and latency challenges.

## License

Riff is licensed under the [MIT License](LICENSE).
