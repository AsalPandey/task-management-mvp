#!/usr/bin/env bash
# Package a completed build without environment files or filesystem links.
set -Eeuo pipefail
[[ $# == 2 ]] || { echo 'Usage: hostinger-package.sh BUILD_DIRECTORY ARCHIVE' >&2; exit 1; }
release="$(realpath -e -- "$1")"
archive="$(realpath -m -- "$2")"
[[ -d "$release" && "$archive" != "$release"/* ]]
[[ -f "$release/artisan" && -f "$release/vendor/autoload.php" && -f "$release/public/build/manifest.json" ]]
[[ -f "$release/.release-sha" && "$(<"$release/.release-sha")" =~ ^[0-9a-f]{40}$ ]]
for private in .env .git public/storage; do
    [[ ! -e "$release/$private" && ! -L "$release/$private" ]] || { echo 'Private/runtime files must not enter a release.' >&2; exit 1; }
done
# Composer dependencies may include documentation links (for example Sentry's
# CLAUDE.md -> AGENTS.md). Only materialize links to regular files inside this
# build; reject escaping, broken, and directory links before tar dereferences.
find "$release" -path "$release/node_modules" -prune -o -type l -print0 |
while IFS= read -r -d '' link; do
    target="$(realpath -e -- "$link")"
    [[ "$target" == "$release"/* && -f "$target" ]] || { echo 'Unsafe dependency link in release.' >&2; exit 1; }
done
# Runtime state and qualification material are never deployment inputs, even
# when a completed build directory contains them. Keep directory skeletons.
tar --dereference --hard-dereference \
    --exclude=./node_modules --exclude=./tests --exclude=./output --exclude=./audit \
    --exclude=./.github --exclude=./docs --exclude=./.idea \
    --exclude='./R*.md' --exclude='./*AUDIT*.md' --exclude=./PRODUCTION_RUNBOOK.md \
    --exclude='./*_DOCUMENTATION.*' \
    --exclude='./scripts/r[0-9]*' --exclude='./playwright*.js' --exclude=./phpunit.xml \
    --exclude=./.env.backup --exclude=./.env.production --exclude=./.env.local --exclude=./auth.json \
    --exclude='./bootstrap/cache/*.php' --exclude='./storage/logs/*' \
    --exclude='./storage/framework/views/*' --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/cache/data/*' --exclude='./storage/app/backup-temp/*' \
    --exclude='./storage/app/private/*' --exclude='./storage/app/public/*' \
    --exclude='./storage/debugbar/*' --exclude='./storage/pail/*' --exclude='./database/*.sql' \
    --exclude='*.sqlite' --exclude='*.sqlite3' --exclude='*.db' --exclude='*.dump' \
    -czf "$archive" -C "$release" .
[[ $(stat -c %s "$archive") -le 134217728 ]] || { echo 'Release archive exceeds 128 MiB.' >&2; exit 1; }
printf 'Release archive prepared without filesystem links.\n'
