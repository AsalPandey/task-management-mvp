#!/usr/bin/env bash
# Low-rate liveness evidence only; run against a dedicated authorized staging host.
set -Eeuo pipefail
[[ ${R45_STAGING_ONLY:-} == 1 ]] || { echo 'Set R45_STAGING_ONLY=1 for a dedicated staging target.' >&2; exit 1; }
: "${STAGING_URL:?Set the dedicated HTTPS staging origin}"
[[ "$STAGING_URL" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?(/[^[:space:]?\#]*)?$ ]] || exit 1
[[ "$STAGING_URL" != *localhost* && "$STAGING_URL" != *127.0.0.1* ]] || exit 1
samples="${R45_SAMPLES:-240}"
[[ "$samples" =~ ^[0-9]+$ && "$samples" -ge 2 && "$samples" -le 1440 ]] || exit 1
umask 077
printf 'utc,status,total_seconds,transport_exit\n'
failed=0
for ((i=1; i<=samples; i++)); do
    result=0
    metrics="$(curl --silent --show-error --output /dev/null --connect-timeout 10 \
        --max-time 20 --write-out '%{http_code},%{time_total}' "${STAGING_URL%/}/up")" || result=$?
    printf '%s,%s,%s\n' "$(date -u +%FT%TZ)" "$metrics" "$result"
    [[ "$result" == 0 && "$metrics" == 200,* ]] || failed=1
    ((i == samples)) || sleep 60
done
exit "$failed"
