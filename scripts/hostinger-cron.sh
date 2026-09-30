#!/usr/bin/env bash
# Install beside hostinger-deploy.sh; invoke once per minute in hPanel.
set -Eeuo pipefail
umask 077
deploy_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
[[ "$(basename "$deploy_root")" == .task-deploy ]] || exit 1
[[ -L "$deploy_root/current" ]] || exit 0
exec 9>"$deploy_root/runtime.lock"
flock -n 9 || exit 0
cd "$deploy_root/current"
php=/opt/alt/php84/usr/bin/php
export PATH=/opt/alt/php84/usr/bin:/usr/local/bin:/usr/bin:/bin
"$php" artisan schedule:run --no-interaction
"$php" artisan queue:work database --queue=notifications,default --stop-when-empty --max-time=50 --sleep=1 --tries=3 --timeout=60 --no-interaction
