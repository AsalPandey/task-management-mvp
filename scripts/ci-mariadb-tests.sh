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
        "${main_database}"|"${r2a_database}"|"${r2a_migration_database}"|"${r2b5_database}"|"${r2b5q_database}"|"${r3a2_database}"|"${r3a3_database}")
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

run_suite() {
    local database="$1"
    shift

    recreate_database "${database}"
    php artisan test "$@"
}

MYSQL_PWD="${DB_PASSWORD}" "${mysql_command[@]}" \
    --execute="SELECT VERSION(), @@port, @@character_set_server;"

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
