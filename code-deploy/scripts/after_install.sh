#!/bin/bash
set -euo pipefail

# Runs on the EC2 instance after CodeDeploy copies the release into place.
# Builds the production .env from .env.example plus Secrets Manager, then
# prepares Laravel. Deliberate properties:
#   - APP_KEY survives deploys (regenerating it invalidated every session
#     and everything ever encrypted with the old key).
#   - Values come from the secret where present, so rotating them is an
#     AWS change, not a code change.
cd /var/www/laravel

# The bundle excludes storage contents, and on a fresh instance the
# directories themselves; artisan dies without them.
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache

# The previous release's key, if any: CodeDeploy does not ship .env, so the
# file on disk is the only copy.
PREVIOUS_APP_KEY=""
if [[ -f .env ]]; then
    PREVIOUS_APP_KEY=$(grep -E '^APP_KEY=' .env | cut -d= -f2- || true)
fi

cp .env.example .env

SECRET_JSON=$(aws secretsmanager get-secret-value \
  --secret-id production-db-credentials \
  --query SecretString \
  --output text)

# jq -e fails the deploy on a missing or null key instead of writing the
# literal string "null" into .env and failing later at migrate.
APP_ENV=$(echo "$SECRET_JSON" | jq -er .environment)
DB_HOST=$(echo "$SECRET_JSON" | jq -er .host)
DB_PORT=$(echo "$SECRET_JSON" | jq -er .port)
DB_DATABASE=$(echo "$SECRET_JSON" | jq -er .dbname)
DB_USERNAME=$(echo "$SECRET_JSON" | jq -er .username)
DB_CONNECTION=$(echo "$SECRET_JSON" | jq -er .engine)

# Optional keys; empty when the secret does not carry them.
APP_URL=$(echo "$SECRET_JSON" | jq -r '.app_url // empty')
SECRET_APP_KEY=$(echo "$SECRET_JSON" | jq -r '.app_key // empty')

if [[ $DB_CONNECTION == "postgres" ]]; then
    DB_CONNECTION="pgsql"
fi

sed -i "s|^#\?\s*APP_ENV=.*|APP_ENV=$APP_ENV|" .env
sed -i "s|^#\?\s*APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^#\?\s*DB_HOST=.*|DB_HOST=$DB_HOST|" .env
sed -i "s|^#\?\s*DB_PORT=.*|DB_PORT=$DB_PORT|" .env
sed -i "s|^#\?\s*DB_DATABASE=.*|DB_DATABASE=$DB_DATABASE|" .env
sed -i "s|^#\?\s*DB_USERNAME=.*|DB_USERNAME=$DB_USERNAME|" .env
sed -i "s|^#\?\s*DB_CONNECTION=.*|DB_CONNECTION=$DB_CONNECTION|" .env

# APP_URL is what absolute links outside a request (queued mail, scheduled
# commands) are built from; the example's localhost must never reach
# production. Prefer the secret, keep a previous value, else refuse.
if [[ -z "$APP_URL" && -f /var/www/laravel/.env.previous_url ]]; then
    APP_URL=$(cat /var/www/laravel/.env.previous_url)
fi
if [[ -z "$APP_URL" ]]; then
    echo "ERROR: app_url is not set in the production-db-credentials secret." >&2
    exit 1
fi
sed -i "s|^#\?\s*APP_URL=.*|APP_URL=$APP_URL|" .env
echo "$APP_URL" > /var/www/laravel/.env.previous_url

# Double-quoted with backslashes and quotes escaped: the one dotenv form
# that round-trips any password the secret may hold (phpdotenv's
# single-quoted strings cannot contain a quote at all).
DB_PASSWORD=$(echo "$SECRET_JSON" | jq -er .password)
DB_PASSWORD=${DB_PASSWORD//\\/\\\\}
DB_PASSWORD=${DB_PASSWORD//\"/\\\"}
grep -v "^#\?\s*DB_PASSWORD=" .env > .env.tmp
echo "DB_PASSWORD=\"$DB_PASSWORD\"" >> .env.tmp
mv .env.tmp .env

# One key for the lifetime of the app: secret first, then whatever the
# previous release used; generate only on a genuinely first deploy.
if [[ -n "$SECRET_APP_KEY" ]]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$SECRET_APP_KEY|" .env
elif [[ -n "$PREVIOUS_APP_KEY" ]]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$PREVIOUS_APP_KEY|" .env
else
    echo "WARNING: no existing APP_KEY found; generating one. Store it in the secret as app_key." >&2
    php artisan key:generate --force
fi

# Artist photos are served through public/storage.
php artisan storage:link --force

# Rebuild caches from the finished .env
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

# Queue workers hold the old code in memory until told to let go.
php artisan queue:restart

# Set directory permissions; .env carries credentials and is not for
# every account on the box.
chmod 640 .env
chown -R nginx:nginx /var/www/laravel
chmod -R 775 storage bootstrap/cache
