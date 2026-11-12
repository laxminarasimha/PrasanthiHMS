#!/usr/bin/env bash
#
# Builds an upload-ready copy of the app for shared hosting (cPanel).
#
#   deploy/build.sh https://hms.example.com
#
# Result: deploy/package/prasanthihms/      the whole app; its "public" folder is the web root
#         deploy/package/prasanthihms.zip   the same thing, zipped for upload
#
# The package contains a production .env with a fresh APP_KEY. Database settings are copied
# from the REMOTE_DB_* lines of backend/.env, with the host set to localhost because on the
# server the database is on the same machine.

set -euo pipefail

APP_URL="${1:-}"
if [[ -z "$APP_URL" ]]; then
  echo "Usage: deploy/build.sh https://your-domain" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/deploy/package"
APP="$OUT/prasanthihms"

echo "==> Building the web app"
(cd "$ROOT/frontend" && npm ci --silent && npm run build --silent)

echo "==> Copying the server code"
rm -rf "$OUT"
mkdir -p "$APP"
rsync -a \
  --exclude '.env' --exclude '.env.*' --exclude 'vendor' --exclude 'node_modules' --exclude 'tests' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' --exclude 'bootstrap/cache/*.php' --exclude 'database/*.sqlite' \
  --exclude 'storage/app/private/*/' --exclude 'storage/app/public/*/' \
  --exclude 'public/spa.html' --exclude 'public/assets' --exclude 'public/hot' --exclude 'public/storage' \
  "$ROOT/backend/" "$APP/"

echo "==> Installing PHP packages (production only)"
(cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

echo "==> Adding the web app to the public folder"
cp "$ROOT/frontend/dist/index.html" "$APP/public/spa.html"
cp -R "$ROOT/frontend/dist/assets" "$APP/public/assets"
find "$ROOT/frontend/dist" -maxdepth 1 -type f ! -name 'index.html' -exec cp {} "$APP/public/" \;

echo "==> Writing the production settings"
remote() { grep -E "^REMOTE_DB_$1=" "$ROOT/backend/.env" | head -1 | cut -d= -f2-; }
KEY="$(cd "$ROOT/backend" && php artisan key:generate --show --no-ansi)"
cat > "$APP/.env" <<ENV
APP_NAME="Prasanthi HMS"
APP_ENV=production
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=$APP_URL
APP_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=$(remote DATABASE)
DB_USERNAME=$(remote USERNAME)
DB_PASSWORD=$(remote PASSWORD)

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV

mkdir -p "$APP/storage/logs" "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/bootstrap/cache"
touch "$APP/storage/logs/.gitkeep"

echo "==> Zipping"
(cd "$OUT" && zip -qr prasanthihms.zip prasanthihms)

echo
echo "Done: $OUT/prasanthihms.zip ($(du -h "$OUT/prasanthihms.zip" | cut -f1))"
echo "Needs PHP $(grep -o '"php": "[^"]*"' "$ROOT/backend/composer.json" | head -1 | cut -d'"' -f4) on the server. See deploy/README.md for the upload steps."
