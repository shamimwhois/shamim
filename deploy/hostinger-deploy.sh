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
# 0. This release must not be a git working tree.
# ---------------------------------------------------------------------------
# The artifact is assembled in CI with `git archive`, so it carries no .git.
# If one is present, somebody has configured hPanel's Git feature to deploy
# onto this path, and two deployers are now fighting over one directory: that
# feature runs its own `composer install` on the host, where Hostinger's PHP
# has `proc_open` in disable_functions, so it fails every time and can also
# re-clone the repository over a release we already activated.
#
# Failing here is deliberate. A silent `git pull` over the release directory
# would replace the CI-built vendor/ with an unbootable tree, so refuse rather
# than proceed. See docs/DEPLOYMENT.md §13.
if [ -e .git ]; then
  echo "ERROR: $STAGING_PATH contains a .git directory." >&2
  echo "       This path is being managed by hPanel's Git deployment feature," >&2
  echo "       which conflicts with the GitHub Actions pipeline in deploy.yml." >&2
  echo "       Remove that deployment (hPanel -> Advanced -> Git) and re-run." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 0b. Assert the host can actually boot this release.
# ---------------------------------------------------------------------------
# The release ships vendor/ pre-built, so Composer is NOT run here. These two
# checks exist because the failure they replace is expensive to read: without
# them, a host that cannot run the app produces an opaque 500 whose cause is
# buried in a log the deployer may not think to open.
if [ ! -f vendor/autoload.php ]; then
  echo "ERROR: vendor/autoload.php is missing; the release was not built with" >&2
  echo "       its Composer dependencies bundled. Build it in CI instead." >&2
  exit 1
fi

# `proc_open` is what Hostinger disables, and it is the single most common
# reason a server-side `composer install` dies with an unreadable
# "Process.php line 147" trace. Report it as a warning: the app itself does
# not need it, only a host-side Composer does.
if ! "$PHP_BIN" -r 'exit(function_exists("proc_open") ? 0 : 1);'; then
  echo "WARN: proc_open is disabled in this PHP. Composer cannot run on this" >&2
  echo "      host, which is expected: the release ships vendor/ pre-built." >&2
fi

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

# Extensions the framework and the production drivers require.
# `composer check-platform-reqs` would be the thorough test, but it is a Composer
# command and Composer is precisely what may be unavailable here, so probe the
# extensions directly with PHP.
#
# Derived from the `ext-*` entries in composer.lock plus the drivers the
# production .env selects (mysql, and the database session/cache/queue stores).
# A missing one of these is the difference between a working site and a bare
# "could not find driver" page, so fail loudly with the fix rather than letting
# the first HTTP request discover it.
missing_ext=""
for ext in pdo_mysql mbstring openssl tokenizer xml dom fileinfo curl ctype json zip; do
  if ! "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
    missing_ext="$missing_ext $ext"
  fi
done

if [ -n "$missing_ext" ]; then
  echo "ERROR: the host PHP is missing required extension(s):$missing_ext" >&2
  echo "       Enable them in hPanel -> Advanced -> PHP Configuration -> Extensions" >&2
  echo "       and re-run the deploy. See docs/DEPLOYMENT.md §1." >&2
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
