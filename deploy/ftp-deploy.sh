#!/usr/bin/env bash
#
# Builds the app and publishes it over FTP to a host where the FTP account can only see the
# web folder itself (no folder above it to keep the code in).
#
#   deploy/ftp-deploy.sh https://hms.example.com
#
# Layout on the server:   <web folder>/index.php, .htaccess, assets/, spa.html   (public files)
#                         <web folder>/app/                                      (Laravel; blocked by app/.htaccess)
#
# Settings come from backend/.env (git-ignored):
#   DEPLOY_FTP_HOST      FTP server name
#   DEPLOY_FTP_USER      FTP user
#   DEPLOY_FTP_PASSWORD  FTP password
#   DEPLOY_FTP_TLS_NAME  optional: the name on the server's certificate when it differs from the
#                        FTP host (shared hosts often use the hosting company's own certificate).
#                        The connection still goes to DEPLOY_FTP_HOST's address and is verified.

set -euo pipefail

APP_URL="${1:-}"
[[ -n "$APP_URL" ]] || { echo "Usage: deploy/ftp-deploy.sh https://your-domain" >&2; exit 1; }
APP_URL="${APP_URL%/}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
setting() { grep -E "^$1=" "$ROOT/backend/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
HOST="$(setting DEPLOY_FTP_HOST)"; USER_NAME="$(setting DEPLOY_FTP_USER)"; PASS="$(setting DEPLOY_FTP_PASSWORD)"; TLS_NAME="$(setting DEPLOY_FTP_TLS_NAME)"
[[ -n "$HOST" && -n "$USER_NAME" && -n "$PASS" ]] || { echo "Set DEPLOY_FTP_HOST, DEPLOY_FTP_USER and DEPLOY_FTP_PASSWORD in backend/.env" >&2; exit 1; }

NAME="${TLS_NAME:-$HOST}"
FTP=(curl -sS --fail --ssl-reqd --user "$USER_NAME:$PASS")
if [[ -n "$TLS_NAME" ]]; then
  FTP+=(--resolve "$TLS_NAME:21:$(dig +short "$HOST" | grep -E '^[0-9.]+$' | head -1)")
fi

"$ROOT/deploy/build.sh" "$APP_URL"

echo "==> Arranging files for a web-folder-only host"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
SITE="$WORK/site"
mkdir -p "$SITE/app"
rsync -a --exclude public "$ROOT/deploy/package/prasanthihms/" "$SITE/app/"
rsync -a "$ROOT/deploy/package/prasanthihms/public/" "$SITE/"
printf 'Require all denied\n' > "$SITE/app/.htaccess"
printf 'User-agent: *\nDisallow: /\n' > "$SITE/robots.txt"
# The entry file finds the app in ./app and tells Laravel the public files are beside it.
sed -i.bak \
  -e "s#__DIR__.'/../storage/#__DIR__.'/app/storage/#" \
  -e "s#__DIR__.'/../vendor/#__DIR__.'/app/vendor/#" \
  -e "s#__DIR__.'/../bootstrap/app.php';#__DIR__.'/app/bootstrap/app.php';\\
\$app->usePublicPath(__DIR__);#" "$SITE/index.php"
rm "$SITE/index.php.bak"
grep -q 'usePublicPath' "$SITE/index.php" && php -l "$SITE/index.php" > /dev/null
(cd "$SITE" && zip -qr ../site.zip .)

# The archive is unpacked by a one-time script with an unguessable name, which deletes itself.
SLUG="$(openssl rand -hex 10)"; TOKEN="$(openssl rand -hex 20)"
# Set DEPLOY_CACHE=0 to leave the site uncached, should a cached release ever misbehave.
DEPLOY_CACHE="${DEPLOY_CACHE:-1}"
cat > "$WORK/unpack.php" <<PHP
<?php
header('Content-Type: text/plain');
if (!hash_equals('$TOKEN', \$_GET['t'] ?? '')) { http_response_code(404); exit; }
set_time_limit(300);
\$zip = new ZipArchive;
if (\$zip->open(__DIR__.'/app/site.zip') !== true) { exit('cannot open archive'); }
\$ok = \$zip->extractTo(__DIR__); \$count = \$zip->numFiles; \$zip->close();
unlink(__DIR__.'/app/site.zip'); unlink(__FILE__);
echo \$ok ? "extracted \$count files" : 'extract failed';
// Settings and routes are worked out once here, instead of on every page request. Anything left
// over from the last release is removed first, and if this fails the site simply runs uncached.
\$caches = fn () => array_merge(glob(__DIR__.'/app/bootstrap/cache/config.php') ?: [], glob(__DIR__.'/app/bootstrap/cache/routes-*.php') ?: []);
array_map('unlink', \$caches());
if (\$ok && '$DEPLOY_CACHE' !== '0') {
  try {
    require __DIR__.'/app/vendor/autoload.php';
    \$app = require __DIR__.'/app/bootstrap/app.php';
    \$app->usePublicPath(__DIR__);
    \$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
    \$kernel->call('config:cache'); \$kernel->call('route:cache');
    echo ' · settings and routes cached';
  } catch (Throwable \$e) {
    array_map('unlink', \$caches());
    echo ' · running uncached: '.\$e->getMessage();
  }
}
PHP

echo "==> Uploading"
# The block on app/ goes up first, so the archive (which holds the database password) is never readable from the web.
"${FTP[@]}" -m 60 --ftp-create-dirs -T "$SITE/app/.htaccess" "ftp://$NAME/app/.htaccess"
"${FTP[@]}" -m 900 -T "$WORK/site.zip" "ftp://$NAME/app/site.zip"
BLOCKED="$(curl -s -m 30 -o /dev/null -w '%{http_code}' "$APP_URL/app/site.zip")"
if [[ "$BLOCKED" != "403" ]]; then
  "${FTP[@]}" -m 60 "ftp://$NAME/" -Q "DELE app/site.zip" -o /dev/null
  echo "Stopped: $APP_URL/app/ is not blocked from the web (got $BLOCKED). The archive was removed again." >&2
  exit 1
fi
"${FTP[@]}" -m 60 -T "$WORK/unpack.php" "ftp://$NAME/unpack-$SLUG.php"

echo "==> Unpacking on the server"
curl -sS -m 300 "$APP_URL/unpack-$SLUG.php?t=$TOKEN"; echo

echo "==> Checking"
echo "site:      $(curl -s -m 30 -o /dev/null -w '%{http_code}' "$APP_URL/")"
echo "api:       $(curl -s -m 30 "$APP_URL/api/health")"
echo "app/.env:  $(curl -s -m 30 -o /dev/null -w '%{http_code}' "$APP_URL/app/.env") (must be 403)"
echo "Remember: cd backend && php artisan db:sync-remote   (if this release adds tables)"
