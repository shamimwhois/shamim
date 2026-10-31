#!/usr/bin/env bash
#
# hostinger-rollback.sh — runs ON the Hostinger server to undo the most recent
# deploy by swapping `.previous` back into place.
#
# Invoked by hand over SSH:
#     bash deploy/hostinger-rollback.sh /home/u123456/domains/example.com
#
# The deploy workflow renames the outgoing release to `<live>.previous` and
# moves the new one into `<live>`; this reverses that single pair of renames,
# so it is fast and needs no rebuild.
#
# NOTE: this does NOT reverse database migrations. Migrations are written to be
# backward compatible (see docs/DEPLOYMENT.md), so the previous code keeps
# working against the newer schema. If a deploy shipped a destructive
# migration, restore the database from a Hostinger backup instead.
set -euo pipefail

LIVE_PATH="${1:?live path is required (e.g. /home/u123456/domains/example.com)}"

PHP_BIN="${PHP_BIN:-php}"

if [ ! -d "${LIVE_PATH}.previous" ]; then
  echo "ERROR: no previous release at ${LIVE_PATH}.previous — nothing to roll back to." >&2
  exit 1
fi

echo "==> Rolling back $LIVE_PATH"

# Park the current (bad) release so it can be inspected, rather than deleting it.
rm -rf "${LIVE_PATH}.failed" || true
mv "$LIVE_PATH" "${LIVE_PATH}.failed"
mv "${LIVE_PATH}.previous" "$LIVE_PATH"

cd "$LIVE_PATH"

# The rolled-back code may expect a different compiled config; rebuild.
if [ -f artisan ]; then
  "$PHP_BIN" artisan config:clear || true
  "$PHP_BIN" artisan route:clear || true
  "$PHP_BIN" artisan view:clear || true
  "$PHP_BIN" artisan config:cache || true
  "$PHP_BIN" artisan route:cache || true
  "$PHP_BIN" artisan view:cache || true
  "$PHP_BIN" artisan up || true
fi

echo "==> Rolled back. The failed release is kept at ${LIVE_PATH}.failed"
