<?php

return [
    'vapid' => [
        'subject' => env('WEBPUSH_VAPID_SUBJECT'),
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
    ],
    'queue' => env('WEBPUSH_QUEUE', 'notifications'),
    'ttl' => (int) env('WEBPUSH_TTL', 3600),
    'stale_after_failures' => (int) env('WEBPUSH_STALE_AFTER_FAILURES', 5),
    'allowed_test_hosts' => explode(',', env('WEBPUSH_ALLOWED_TEST_HOSTS', 'push.example.test')),
];
