# Riff

Riff is an experimental local music discovery app. It helps listeners find independent artists near a chosen area and gives bands, solo artists, rappers, producers, and other music makers an artist identity they can use to share their work.

The core idea is map-led discovery: explore local artists, hear their music, and find a reason to follow them or see them live. The project also contains early experiments with artist posts, events, and livestreaming. Those parts are still in progress; see the [development roadmap](ROADMAP.md).

## Project status

Riff is a prototype under active development, not a finished public service. The codebase has authentication, artist records and memberships, a Leaflet map, and early social and streaming implementations. Some screens and interactions are experimental or use placeholder data. In particular, map and feed experiences are not yet a complete production discovery flow.

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
php artisan db:seed
```

The seeder creates sample artists, users, and memberships. Use only in a local development database: it adds records and is not intended for production data.

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

- `app/Models` — users, artists, posts, comments, likes, and livestream records.
- `app/Http/Controllers` — request handling for maps, artist identities, feeds, profiles, and streams.
- `app/Services` and `app/Actions` — geocoding, map data, and event lookup.
- `database/migrations` — database schema.
- `resources/js/pages` — Inertia/Vue screens, including the map and experimental feed/stream pages.
- `routes/web.php` — web routes.
- `tests` — PHPUnit feature and unit tests.
- `ROADMAP.md` — product direction, current gaps, and proposed milestones.

## Tests and code style

Run the PHPUnit suite with:

```bash
composer test
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

`.github/workflows/ci.yml` runs Pint, the Vite production build, and the PHPUnit suite on every pull request and on pushes to non-`main` branches. `.github/workflows/deploy.yml` runs the same checks and then deploys to EC2 via AWS CodeDeploy on pushes to `main`; a failing check blocks the deploy. The CodeDeploy install script in `code-deploy/scripts` builds the production `.env` from `.env.example`, overriding the database settings from AWS Secrets Manager plus `APP_ENV`, `APP_DEBUG`, and `APP_URL`.

## Product direction

Riff is focused on helping people discover artists who may not appear in their usual streaming recommendations. The recommended next step is to make artist profiles, nearby map results, and music previews dependable in a small pilot area, then add local events and community features based on what listeners and artists use. Real-time livestreaming and remote live jams remain exploratory because they add significant reliability, moderation, and latency challenges.

## License

Riff is licensed under the [MIT License](LICENSE).
