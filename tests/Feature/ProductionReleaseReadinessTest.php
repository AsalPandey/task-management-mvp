<?php

test('production environment template has secure explicit operational defaults', function () {
    $environment = file_get_contents(base_path('.env.example'));

    foreach ([
        'APP_NAME="Task Management MVP"',
        'APP_ENV=production',
        'APP_DEBUG=false',
        'APP_URL=https://tasks.example.com',
        'SESSION_DRIVER=database',
        'SESSION_SECURE_COOKIE=true',
        'SESSION_HTTP_ONLY=true',
        'SESSION_SAME_SITE=lax',
        'CACHE_STORE=database',
        'QUEUE_CONNECTION=database',
        'QUEUE_FAILED_DRIVER=database-uuids',
        'FILESYSTEM_DISK=local',
        'LOCAL_FILESYSTEM_SERVE=false',
        'BACKUP_DISKS=s3',
    ] as $expected) {
        expect($environment)->toContain($expected);
    }

    expect($environment)
        ->not->toContain('APP_URL=http://localhost')
        ->not->toContain('DB_USERNAME=root');
});

test('private local filesystem does not register a serving route', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();

    $this->get('/storage/private-release-secret.txt')->assertNotFound();
});

test('production responses carry release security headers without exposing health details', function () {
    $this->app['env'] = 'production';

    $response = $this->get('https://tasks.example.com/up');

    $response
        ->assertOk()
        ->assertSeeText('OK')
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee('APP_KEY')
        ->assertDontSee(base_path())
        ->assertDontSee('fonts.bunny.net')
        ->assertDontSee('cdn.jsdelivr.net');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("worker-src 'self'");
});

test('core browser libraries are provided by the locked local vite build', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry = file_get_contents(resource_path('js/app.js'));
    $views = collect([
        resource_path('views/layouts/app.blade.php'),
        resource_path('views/layouts/guest.blade.php'),
        resource_path('views/analytics.blade.php'),
        resource_path('views/team-member-analytics.blade.php'),
    ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");

    expect($package['devDependencies'])
        ->toHaveKeys(['chart.js', 'sweetalert2']);
    expect($entry)
        ->toContain("import Chart from 'chart.js/auto'")
        ->toContain("import Swal from 'sweetalert2'");
    expect($views)
        ->toContain("@vite(['resources/css/app.css', 'resources/js/app.js'])")
        ->not->toContain('cdn.jsdelivr.net')
        ->not->toContain('fonts.bunny.net');
});
