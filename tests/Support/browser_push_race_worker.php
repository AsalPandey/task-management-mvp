<?php

use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushDelivery;
use App\Models\BrowserPushSubscription;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BarrierBrowserPushTransport;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $userId, $notificationId, $subscriptionId, $logFile, $readyFile, $releaseFile, $mode] = $argv;

try {
    if ($mode === 'claim-crash') {
        DB::transaction(function () use ($subscriptionId, $notificationId): void {
            BrowserPushSubscription::query()->whereKey((int) $subscriptionId)->lockForUpdate()->firstOrFail();
            $delivery = BrowserPushDelivery::query()->firstOrCreate([
                'browser_push_subscription_id' => (int) $subscriptionId,
                'notification_id' => $notificationId,
            ]);
            $delivery->forceFill([
                'status' => 'processing', 'attempt_count' => $delivery->attempt_count + 1,
                'claimed_at' => now(), 'lease_expires_at' => now()->addMinutes(5),
            ])->save();
        });
        echo json_encode(['result' => 'claimed-then-exited'], JSON_THROW_ON_ERROR);
        exit(0);
    }

    $payload = [
        'title' => 'Race', 'body' => 'Race',
        'data' => ['type' => 'task_updated', 'target' => '/notifications/all'],
    ];
    $transport = new BarrierBrowserPushTransport(
        $logFile,
        $readyFile === '-' ? '' : $readyFile,
        $releaseFile === '-' ? '' : $releaseFile,
        $mode === 'temporary-failure',
    );
    (new SendBrowserPushNotification(
        (int) $userId,
        $notificationId,
        $payload,
        $subscriptionId === 'all' ? null : (int) $subscriptionId,
    ))->handle($transport);
    echo json_encode(['result' => 'finished'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
