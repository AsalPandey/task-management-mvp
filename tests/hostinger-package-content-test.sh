#!/usr/bin/env bash
# Content boundaries can be checked without filesystem-link privileges.
set -Eeuo pipefail
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT
build="$scratch/build"
mkdir -p "$build/vendor" "$build/public/build" "$build/database/migrations"
mkdir -p "$build/tests" "$build/output" "$build/audit" "$build/.github" "$build/bootstrap/cache"
mkdir -p "$build/storage/logs" "$build/storage/framework/views" "$build/storage/app/private"
touch "$build/artisan" "$build/vendor/autoload.php" "$build/public/build/manifest.json"
touch "$build/database/migrations/required.php" "$build/.env.example"
printf '%040d\n' 1 > "$build/.release-sha"
for path in tests/test-account.php output/audit.txt audit/evidence.json .github/ci.yml \
    bootstrap/cache/config.php storage/logs/laravel.log storage/framework/views/compiled.php \
    storage/app/private/customer.txt database/test.sqlite database/backup.sql; do
    printf 'Synthetic private fixture\n' > "$build/$path"
done
bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/release.tar.gz"
tar -tzf "$scratch/release.tar.gz" > "$scratch/list"
for required in artisan vendor/autoload.php public/build/manifest.json database/migrations/required.php .env.example .release-sha; do
    grep -Fx "./$required" "$scratch/list" >/dev/null
done
if grep -E '^\./(tests|output|audit|\.github)/|^\./bootstrap/cache/.*\.php$|^\./storage/(logs|framework/views|app/private)/.+|\.(sqlite|sql)$' "$scratch/list"; then
    echo 'Private or development content entered the release.' >&2; exit 1
fi
touch "$build/.env"
if bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/unsafe.tar.gz"; then
    echo 'A real environment file was accepted.' >&2; exit 1
fi
printf 'Required files retained; private/runtime/development content excluded.\n'
