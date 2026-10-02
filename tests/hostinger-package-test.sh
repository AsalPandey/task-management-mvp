#!/usr/bin/env bash
set -Eeuo pipefail
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT
build="$scratch/build"
mkdir -p "$build/vendor/example" "$build/public/build"
touch "$build/artisan" "$build/vendor/autoload.php" "$build/public/build/manifest.json"
printf '%040d\n' 1 > "$build/.release-sha"
printf 'Dependency documentation\n' > "$build/vendor/example/AGENTS.md"
ln -s AGENTS.md "$build/vendor/example/CLAUDE.md"
bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/release.tar.gz"
[[ "$(tar -xOzf "$scratch/release.tar.gz" ./vendor/example/CLAUDE.md)" == 'Dependency documentation' ]]
if tar -tvzf "$scratch/release.tar.gz" | awk 'substr($0,1,1) == "l" || substr($0,1,1) == "h" { found=1 } END { exit !found }'; then
    echo 'Packaged dependency link was not materialized.' >&2; exit 1
fi
ln -s "$scratch" "$build/escaping-directory"
if bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/unsafe.tar.gz"; then
    echo 'Directory/escaping link was accepted.' >&2; exit 1
fi
unlink "$build/escaping-directory"
printf 'Private fixture\n' > "$scratch/private"
ln -s "$scratch/private" "$build/escaping-file"
if bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/unsafe.tar.gz"; then
    echo 'Escaping file link was accepted.' >&2; exit 1
fi
unlink "$build/escaping-file"
touch "$build/.env"
if bash "$repo/scripts/hostinger-package.sh" "$build" "$scratch/unsafe.tar.gz"; then
    echo 'Environment file was accepted.' >&2; exit 1
fi
printf 'Dependency-link packaging and private-file rejection checks passed.\n'
