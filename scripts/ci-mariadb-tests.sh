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
readonly r53_dense10_database="task_management_r43_r53_ci_dense10000"
readonly r53_dense25_database="task_management_r43_r53_ci_dense25000"
readonly r51_concurrency_database="task_management_r51_concurrency_ci"
readonly r52_concurrency_database="task_management_r52_concurrency_ci"
readonly r43_concurrency_database="task_management_r43_concurrency_ci"
readonly r61_account_database="task_management_r6_r61_account_ci"
readonly r61_responsibility_database="task_management_r6_r61_responsibility_ci"

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
        "${main_database}"|"${r2a_database}"|"${r2a_migration_database}"|"${r2b5_database}"|"${r2b5q_database}"|"${r3a2_database}"|"${r3a3_database}"|"${r3c_fresh_database}"|"${r42_membership_database}"|"${r42_upgrade_database}"|"${r42_reset_database}"|"${r42_timezone_database}"|"${r43_qualification_database}"|"${r53_dense10_database}"|"${r53_dense25_database}"|"${r43_concurrency_database}"|"${r51_concurrency_database}"|"${r52_concurrency_database}")
            return 0
            ;;
        "${r61_account_database}"|"${r61_responsibility_database}"|task_management_r6_company_250|task_management_r6_company_500|task_management_r6_company)
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
run_suite "${r51_concurrency_database}" tests/Feature/R51ProjectWriterMariaDbConcurrencyTest.php
run_suite "${r52_concurrency_database}" tests/Feature/R52EligibilityMariaDbConcurrencyTest.php
run_suite "${r61_account_database}" tests/Feature/R61AccountMariaDbConcurrencyTest.php
run_suite "${r61_responsibility_database}" tests/Feature/R61ResponsibilityMariaDbConcurrencyTest.php

# Dense membership response budgets run in fresh 128M PHP processes.
mkdir -p output/r61-ci
for size in 250 500 1000; do
    database="task_management_r6_company_${size}"
    if [[ "$size" == 1000 ]]; then database=task_management_r6_company; fi
    recreate_database "$database"
    php scripts/r61-prepare-roster.php "$size" > "output/r61-ci/fixture-$size.json"
    php -d memory_limit=128M scripts/r61-check-roster.php > "output/r61-ci/roster-$size.json"
done
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

# R5.3: dense company fixtures, unchanged 128M process limit, real reconciliation
# with durable delivery and an independent state/version/provenance oracle.
export APP_TIMEZONE=Asia/Kathmandu
mkdir -p output/r5-3-ci
for size in 10000 25000; do
    if [[ "$size" == 10000 ]]; then database="$r53_dense10_database"; else database="$r53_dense25_database"; fi
    recreate_database "$database"
    php artisan db:seed --force --no-interaction
    php -d memory_limit=128M scripts/r43-prepare-performance.php "$size" 1 0 > "output/r5-3-ci/fixture-$size.json"
    MYSQL_PWD="$DB_PASSWORD" "${mysql_command[@]}" --database="$database" --execute="INSERT INTO users (name,email,password,active,role_id,created_at,updated_at) SELECT 'Second capacity manager','second@r53.example.invalid',password,1,role_id,NOW(),NOW() FROM users WHERE email='manager@r43.example.invalid';"
    php -d memory_limit=128M scripts/r53-check-administration.php > "output/r5-3-ci/administration-$size.json"
done

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
