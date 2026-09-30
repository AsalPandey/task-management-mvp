#!/usr/bin/env bash
# Install at <site>/.task-deploy/hostinger-deploy.sh, outside the document root.
set -Eeuo pipefail
umask 077

deploy_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
[[ "$(basename "$deploy_root")" == .task-deploy ]] || { echo 'Invalid deployment directory.' >&2; exit 1; }
site_root="$(dirname "$deploy_root")"
public_root="$site_root/public_html"
shared="$deploy_root/shared"
php=/opt/alt/php84/usr/bin/php
export PATH=/opt/alt/php84/usr/bin:/usr/local/bin:/usr/bin:/bin

request="${SSH_ORIGINAL_COMMAND:-}"
[[ "$request" =~ ^deploy\ ([0-9a-f]{40})$ ]] || { echo 'Only a release deployment is permitted.' >&2; exit 1; }
sha="${BASH_REMATCH[1]}"
[[ -s "$shared/.env" && -d "$shared/storage" ]] || { echo 'Private production environment is not ready.' >&2; exit 1; }
mkdir -p "$deploy_root/releases" "$deploy_root/incoming"
exec 9>"$deploy_root/runtime.lock"
flock -w 150 9 || { echo 'Another runtime operation is still running.' >&2; exit 1; }

archive="$(mktemp "$deploy_root/incoming/release.XXXXXX.tar.gz")"
timeout 120 head -c 134217729 > "$archive"
[[ $(stat -c %s "$archive") -le 134217728 ]] || { echo 'Release archive exceeds 128 MiB.' >&2; exit 1; }
# Reject traversal and archive links before extraction. Production storage links
# are created here, never accepted from an uploaded archive.
tar -tzf "$archive" | awk '/(^\/|(^|\/)\.\.(\/|$))/ { bad=1 } END { exit bad }'
if tar -tvzf "$archive" | awk 'substr($0,1,1) == "l" || substr($0,1,1) == "h" { found=1 } END { exit !found }'; then
    echo 'Archive links are not permitted.' >&2
    exit 1
fi
release="$(mktemp -d "$deploy_root/releases/$sha.XXXXXX")"
tar -xzf "$archive" --no-same-owner -C "$release"
[[ -f "$release/.release-sha" && "$(<"$release/.release-sha")" == "$sha" ]]
[[ -f "$release/artisan" && -f "$release/vendor/autoload.php" && -f "$release/public/build/manifest.json" ]]
[[ ! -e "$release/.env" && ! -e "$release/public/storage" ]]
ln -s "$shared/.env" "$release/.env"
mv "$release/storage" "$release/storage-template"
ln -s "$shared/storage" "$release/storage"
chmod 0755 "$release" "$release/public"
find "$release/bootstrap/cache" -type d -exec chmod 0750 {} +
cd "$release"
"$php" scripts/hostinger-health.php

previous="$(readlink "$deploy_root/current" || true)"
legacy=''
switched=0
maintenance=0
recover() {
    result=$?
    trap - EXIT
    if [[ $result -ne 0 ]]; then
        echo "Deployment failed for $sha; schema is never automatically rolled back." >&2
        if [[ $switched == 1 ]]; then
            "$php" artisan down --retry=30 --no-interaction || true
            maintenance=1
        fi
        if [[ $switched == 1 && -z "$previous" && -n "$legacy" && -L "$public_root" && "$(readlink "$public_root")" == "$deploy_root/current/public" ]]; then
            unlink "$public_root"
            mv "$legacy" "$public_root"
        fi
        # Schema compatibility must be reviewed before any code rollback.
        # After a schema-stage failure keep maintenance in place for review.
        # Building/validation failures above this point leave the live site alone.
        [[ $maintenance == 0 ]] || echo 'Maintenance retained; inspect the private deploy log before recovery.' >&2
    fi
    exit "$result"
}
trap recover EXIT

# Serialize scheduler/workers with this lock and back up before schema changes.
"$php" artisan backup:run --only-db --disable-notifications --no-interaction
"$php" artisan down --retry=30 --no-interaction
maintenance=1
"$php" artisan migrate --seed --force --no-interaction
"$php" artisan storage:link --no-interaction
"$php" artisan optimize --no-interaction
"$php" artisan queue:restart --no-interaction

if [[ -e "$public_root" && ! -L "$public_root" ]]; then
    [[ -d "$public_root" && -f "$public_root/artisan" && -f "$public_root/composer.json" ]] || { echo 'Unexpected document root; refusing to move it.' >&2; exit 1; }
    [[ -z "$previous" ]] || { echo 'Existing release with an unexpected document root.' >&2; exit 1; }
    legacy="$deploy_root/legacy-public_html-$(date -u +%Y%m%dT%H%M%SZ)"
    [[ ! -e "$legacy" ]]
    mv "$public_root" "$legacy"
elif [[ -L "$public_root" ]]; then
    [[ "$(readlink "$public_root")" == "$deploy_root/current/public" ]] || { echo 'Unexpected document-root symlink.' >&2; exit 1; }
fi
ln -s "$release" "$deploy_root/current.next.$$"
mv -Tf "$deploy_root/current.next.$$" "$deploy_root/current"
switched=1
if [[ ! -L "$public_root" ]]; then
    ln -s "$deploy_root/current/public" "$public_root"
fi
"$php" artisan up --no-interaction
maintenance=0
origin="https://$(basename "$site_root")"
curl -fsS --retry 3 --retry-delay 2 --max-time 20 "$origin/up" >/dev/null
curl -fsS --retry 3 --retry-delay 2 --max-time 20 "$origin/login" >/dev/null
for path in .env composer.json .git/config; do
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$origin/$path")"
    [[ "$status" == 403 || "$status" == 404 ]] || { echo 'Private-source isolation check failed.' >&2; exit 1; }
done
unlink "$archive"
printf 'Released %s successfully. Previous release and private state retained.\n' "$sha"
