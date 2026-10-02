<?php

test('hostinger release is gated by quality MariaDB and real browser qualification', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    expect($workflow)
        ->toContain('needs: [quality, mariadb, browser]')
        ->toContain('name: Clean-company browser critical path')
        ->toContain('npm run test:browser')
        ->toContain("vars.HOSTINGER_DEPLOY_ENABLED == 'true'")
        ->toContain('git archive "$GITHUB_SHA"')
        ->toContain('test ! -f .env')
        ->toContain('composer install --no-dev')
        ->toContain('name: vite-${{ github.sha }}')
        ->toContain('StrictHostKeyChecking=yes')
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('continue-on-error');
});

test('hostinger receiver preserves private state and never rolls schema back', function () {
    $receiver = file_get_contents(dirname(__DIR__, 2).'/scripts/hostinger-deploy.sh');
    $runtime = file_get_contents(dirname(__DIR__, 2).'/scripts/hostinger-cron.sh');

    expect($receiver)
        ->toContain('== .task-deploy')
        ->toContain('runtime.lock')
        ->toContain('backup:run --only-db')
        ->toContain('migrate --seed --force')
        ->toContain('$shared/.env')
        ->toContain('legacy-public_html-')
        ->toContain('current/public')
        ->toContain('Maintenance retained')
        ->not->toContain('migrate:fresh')
        ->not->toContain('migrate:rollback')
        ->not->toContain('rm -rf')
        ->not->toContain('chmod 0777');

    expect($runtime)
        ->toContain('runtime.lock')
        ->toContain('schedule:run')
        ->toContain('--queue=notifications,default')
        ->toContain('--stop-when-empty')
        ->toContain('--max-time=50');
});
