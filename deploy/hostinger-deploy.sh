#!/usr/bin/env bash
#
# hostinger-deploy.sh — runs ON the Hostinger server, inside the newly uploaded
# release directory, immediately before that directory is swapped into place.
#
# Invoked by .github/workflows/deploy.yml as:
#     bash deploy/hostinger-deploy.sh <staging_path> <live_path>
#
# Everything here must be safe to run on shared hosting, which means:
#   - no root, no systemd, no docker
#   - PHP is a CLI binary reachable as `php` (see $PHP_BIN below)
#   - no Composer or Node on the host is assumed: vendor/ and public/build/
#     were already built by CI and uploaded inside this release
#
# The script is deliberately fail-fast (`set -e`): if any step fails the
# workflow stops and the swap never happens, so the live site keeps running the
# previous, known-good release.
set -euo pipefail

STAGING_PATH="${1:?staging path is required}"
LIVE_PATH="${2:?live path is required}"

# Hostinger's CLI PHP is usually on the PATH, but cPanel-style hosts sometimes
# expose versioned binaries instead. Override with PHP_BIN if a deploy fails
# with "php: command not found".
PHP_BIN="${PHP_BIN:-php}"

cd "$STAGING_PATH"

echo "==> Deploying $(basename "$STAGING_PATH") to $LIVE_PATH"
echo "==> PHP: $("$PHP_BIN" -v | head -n1)"

# ---------------------------------------------------------------------------
# 1. Sanity: the artifact must actually be a Laravel app.
# ---------------------------------------------------------------------------
if [ ! -f artisan ]; then
  echo "ERROR: artisan not found in the release; refusing to deploy." >&2
  exit 1
fi

if [ ! -f .env ]; then
  echo "ERROR: .env missing. The workflow writes it before this script runs." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 2. Writable runtime directories.
# ---------------------------------------------------------------------------
# storage/ and bootstrap/cache must be writable by the web-server user. Hosting
# panels sometimes reset permissions on upload, so re-assert them here.
# 775 rather than 777: group-writable is enough and avoids world-write.
mkdir -p \
  storage/app/private \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R 775 storage bootstrap/cache || true

# A stale .env from a previous deploy could contain APP_KEY from another
# install. CI always ships one, and this asserts it is non-empty so a broken
# secret fails the deploy loudly instead of producing "No application
# encryption key" 500s on first request.
if ! grep -qE '^APP_KEY=base64:.+' .env; then
  echo "ERROR: APP_KEY is missing or empty in .env." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 3. Database migrations.
# ---------------------------------------------------------------------------
# --force is required in production, where Artisan otherwise refuses to run
# destructive migrations unattended. Migrations run AGAINST THE STAGING code
# but write to the live database, so they must be backward compatible with the
# release currently serving traffic (add columns, never rename or drop in the
# same deploy that starts using the new shape).
"$PHP_BIN" artisan migrate --force --no-interaction

# ---------------------------------------------------------------------------
# 4. Storage symlink.
# ---------------------------------------------------------------------------
# public/storage -> ../storage/app/public, so uploaded media is reachable.
# `storage:link` is idempotent and safe to re-run. It is skipped gracefully if
# the symlink already exists and the function is unsupported on the host.
"$PHP_BIN" artisan storage:link --force || echo "WARN: storage:link skipped"

# ---------------------------------------------------------------------------
# 5. Rebuild caches against the new code.
# ---------------------------------------------------------------------------
# Cleared first so nothing from the previous release survives, then rebuilt.
# config:cache in particular must run AFTER the .env above is in place, or the
# app boots with stale configuration.
"$PHP_BIN" artisan config:clear || true
"$PHP_BIN" artisan route:clear || true
"$PHP_BIN" artisan view:clear || true
"$PHP_BIN" artisan event:clear || true

"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# ---------------------------------------------------------------------------
# 6. Bring the site out of maintenance mode, if it was left in it.
# ---------------------------------------------------------------------------
# A deploy that died mid-way may have left the app down. `up` is a no-op when
# the app is already live.
"$PHP_BIN" artisan up || true

echo "==> Deploy steps complete for $STAGING_PATH"
echo "==> The workflow will now swap this release into $LIVE_PATH"
