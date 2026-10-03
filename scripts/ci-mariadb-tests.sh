#!/usr/bin/env bash

set -euo pipefail

: "${DB_HOST:?DB_HOST must be set}"
: "${DB_PORT:?DB_PORT must be set}"
: "${DB_USERNAME:?DB_USERNAME must be set}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"

readonly main_database="task_management_phase28_ci_main"
readonly r2a_database="task_management_phase28_r2a_ci"
readonly r2a_migration_database="task_management_phase28_r2a_ci_migration"
readonly r2b5_database="task_management_phase28_r2b5_ci"
readonly r2b5q_database="task_management_phase28_r2b5q_ci"
readonly r3a2_database="task_management_phase28_r3a2_ci"
readonly r3a3_database="task_management_phase28_r3a3_ci"
readonly r42_membership_database="task_management_r42_membership_ci"
readonly r42_upgrade_database="task_management_r42_upgrade_ci"
readonly r42_reset_database="task_management_r42_reset_ci"
readonly r42_timezone_database="task_management_r42_timezone_ci"
readonly r3c_fresh_database="task_management_r3c_fresh_ci"
readonly r43_qualification_database="task_management_r43_ci_qualification"
readonly r43_concurrency_database="task_management_r43_concurrency_ci"

mysql_command=(
    mysql
    --protocol=tcp
    --host="${DB_HOST}"
    --port="${DB_PORT}"
    --user="${DB_USERNAME}"
    --batch
    --skip-column-names
)

database_is_approved() {
    case "$1" in
        "${main_database}"|"${r2a_database}"|"${r2a_migration_database}"|"${r2b5_database}"|"${r2b5q_database}"|"${r3a2_database}"|"${r3a3_database}"|"${r3c_fresh_database}"|"${r42_membership_database}"|"${r42_upgrade_database}"|"${r42_reset_database}"|"${r42_timezone_database}"|"${r43_qualification_database}"|"${r43_concurrency_database}")
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

recreate_database() {
    local database="$1"

    if ! database_is_approved "${database}"; then
        echo "Refusing to operate on unapproved database: ${database}" >&2
        exit 1
    fi

    MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" \
        --execute="DROP DATABASE IF EXISTS \`${database}\`; CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    export DB_DATABASE="${database}"
    php artisan migrate:fresh --force --no-interaction
}

qualify_fresh_install() {
    local pass="$1"
    local original_app_env="${APP_ENV}"
    local original_cache_store="${CACHE_STORE}"
    local original_queue_connection="${QUEUE_CONNECTION}"
    local original_session_driver="${SESSION_DRIVER}"

    export APP_ENV=production
    export CACHE_STORE=database
    export QUEUE_CONNECTION=database
    export SESSION_DRIVER=database

    MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" \
        --execute="DROP DATABASE IF EXISTS \`${r3c_fresh_database}\`; CREATE DATABASE \`${r3c_fresh_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    export DB_DATABASE="${r3c_fresh_database}"

    local table_count
    table_count="$(MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" \
        --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${r3c_fresh_database}';")"
    test "${table_count}" = "0"

    php artisan migrate --seed --force --no-interaction
    php artisan app:setup-company --no-interaction
    R3C_EXPECTED_MANAGER_PASSWORD="${INITIAL_MANAGER_PASSWORD}" php tests/Support/r3c_fresh_install_check.php

    INITIAL_MANAGER_PASSWORD="A-different-rerun-secret-that-must-not-apply-456!" \
        php artisan app:setup-company --no-interaction
    R3C_EXPECTED_MANAGER_PASSWORD="${INITIAL_MANAGER_PASSWORD}" php tests/Support/r3c_fresh_install_check.php
    echo "R3C fresh-install pass ${pass} succeeded."

    export APP_ENV="${original_app_env}"
    export CACHE_STORE="${original_cache_store}"
    export QUEUE_CONNECTION="${original_queue_connection}"
    export SESSION_DRIVER="${original_session_driver}"
}

run_suite() {
    local database="$1"
    shift

    recreate_database "${database}"
    php artisan test "$@"
}

MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" \
    --execute="SELECT VERSION(), @@port, @@character_set_server;"

qualify_fresh_install 1
qualify_fresh_install 2

run_suite "${main_database}"

run_suite "${r2a_database}" \
    tests/Feature/R2aAccountMariaDbConcurrencyTest.php

recreate_database "${r2a_migration_database}"
php tests/Support/r2a_migration_check.php

run_suite "${r2b5_database}" \
    tests/Feature/TaskNotificationDeliveryMariaDbConcurrencyTest.php

run_suite "${r2b5q_database}" \
    tests/Feature/BrowserPushMariaDbConcurrencyTest.php \
    tests/Feature/TaskNotificationMutationMariaDbConcurrencyTest.php

run_suite "${r3a2_database}" \
    tests/Feature/R3A2MariaDbConcurrencyTest.php

run_suite "${r3a3_database}" \
    tests/Feature/R3A3MariaDbConcurrencyTest.php

# R4.2 gates: effects under real contention, reverse DDL, and truthful old-schema upgrade.
run_suite "${r42_membership_database}" tests/Feature/ProjectMembershipMariaDbConcurrencyTest.php
run_suite "${r43_concurrency_database}" tests/Feature/R43NotificationMariaDbConcurrencyTest.php
recreate_database "${r42_reset_database}"
php artisan db:seed --force --no-interaction
php artisan migrate:reset --force --no-interaction
test "$(MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" --execute="SELECT COUNT(*) FROM migrations;")" = "0"
php artisan migrate --seed --force --no-interaction
php artisan about --only=environment,drivers

# The historical upgrade helper must start before current migrations exist.
MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" --execute="DROP DATABASE IF EXISTS \`${r42_upgrade_database}\`; CREATE DATABASE \`${r42_upgrade_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
export DB_DATABASE="${r42_upgrade_database}"
php tests/Support/r42_legacy_upgrade.php

recreate_database "${r42_timezone_database}"
php artisan db:seed --force --no-interaction
export APP_ENV=production APP_DEBUG=false CACHE_STORE=database QUEUE_CONNECTION=database SESSION_DRIVER=database
export APP_CONFIG_CACHE=storage/framework/r42-config.php APP_ROUTES_CACHE=storage/framework/r42-routes.php APP_EVENTS_CACHE=storage/framework/r42-events.php
for timezone in Asia/Kathmandu America/New_York; do
    export APP_TIMEZONE="${timezone}"
    php artisan config:clear
    php artisan migrate:fresh --seed --force --no-interaction
    php artisan optimize
    php tests/Support/r42_cached_timezone_check.php
done
php artisan config:clear
php artisan route:clear
php artisan event:clear

# R4.3 is a separate 10k resource gate; the normal suite keeps small deterministic budgets.
export APP_TIMEZONE=Asia/Kathmandu
export APP_CONFIG_CACHE=storage/framework/r43-config.php APP_ROUTES_CACHE=storage/framework/r43-routes.php APP_EVENTS_CACHE=storage/framework/r43-events.php
recreate_database "${r43_qualification_database}"
php artisan db:seed --force --no-interaction
mkdir -p output/r43
php scripts/r43-prepare-performance.php 10000 20 10000 > output/r43/ci-oracle.json
php artisan optimize
php -d memory_limit=128M scripts/r43-check-performance.php > output/r43/ci-performance.json
php artisan config:clear
php artisan route:clear
php artisan event:clear
