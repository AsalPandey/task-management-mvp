<?php

namespace Tests\Feature;

use App\Models\BrowserPushDelivery;
use App\Models\BrowserPushSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BrowserPushMariaDbConcurrencyTest extends TestCase
{
    public function test_duplicate_jobs_make_one_transport_attempt_for_one_subscription(): void
    {
        $this->requireDisposableMariaDb();
        [$user, $subscriptions] = $this->fixture(1);
        $notification = (string) Str::uuid();
        $files = $this->files();
        try {
            $first = $this->worker($user->id, $notification, (string) $subscriptions[0]->id, $files, true);
            $first->start();
            $this->waitReady($first, $files['ready']);
            $second = $this->worker($user->id, $notification, (string) $subscriptions[0]->id, $files, false);
            $second->start();
            $second->wait();
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $this->assertTrue($first->isRunning(), 'Lease owner did not overlap the duplicate worker.');
            file_put_contents($files['release'], 'go');
            $first->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertSame([$subscriptions[0]->id], $this->attempts($files['log']));
            $this->assertSame('sent', BrowserPushDelivery::query()->sole()->status);
        } finally {
            $this->cleanup($user, $subscriptions, $files);
        }
    }

    public function test_two_subscriptions_are_independent_under_competing_workers(): void
    {
        $this->requireDisposableMariaDb();
        [$user, $subscriptions] = $this->fixture(2);
        $notification = (string) Str::uuid();
        $files = $this->files();
        try {
            $workers = [];
            foreach ($subscriptions as $index => $subscription) {
                $worker = $this->worker($user->id, $notification, (string) $subscription->id, $files, false, $index === 0 ? 'temporary-failure' : 'success');
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $attempts = $this->attempts($files['log']);
            sort($attempts);
            $expected = array_map(fn ($subscription) => $subscription->id, $subscriptions);
            sort($expected);
            $this->assertSame($expected, $attempts);
            $this->assertSame(1, BrowserPushDelivery::query()->where('notification_id', $notification)->where('status', 'sent')->count());
            $this->assertSame(1, BrowserPushDelivery::query()->where('notification_id', $notification)->where('status', 'failed')->count());
        } finally {
            $this->cleanup($user, $subscriptions, $files);
        }
    }

    public function test_temporary_failure_backoff_and_expired_lease_recover_across_workers(): void
    {
        $this->requireDisposableMariaDb();
        [$user, $subscriptions] = $this->fixture(1);
        $notification = (string) Str::uuid();
        $files = $this->files();
        try {
            $failed = $this->worker($user->id, $notification, (string) $subscriptions[0]->id, $files, false, 'temporary-failure');
            $failed->run();
            $this->assertTrue($failed->isSuccessful(), $failed->getErrorOutput());
            $blocked = $this->worker($user->id, $notification, (string) $subscriptions[0]->id, $files, false);
            $blocked->run();
            $this->assertCount(1, $this->attempts($files['log']));
            $delivery = BrowserPushDelivery::query()->sole();
            $this->assertSame('failed', $delivery->status);
            $delivery->forceFill(['next_attempt_at' => now()->subSecond()])->save();
            $retry = $this->worker($user->id, $notification, (string) $subscriptions[0]->id, $files, false);
            $retry->run();
            $this->assertTrue($retry->isSuccessful(), $retry->getErrorOutput());
            $this->assertCount(2, $this->attempts($files['log']));
            $this->assertSame('sent', $delivery->refresh()->status);

            $leaseNotification = (string) Str::uuid();
            $crashed = $this->worker($user->id, $leaseNotification, (string) $subscriptions[0]->id, $files, false, 'claim-crash');
            $crashed->run();
            $this->assertTrue($crashed->isSuccessful(), $crashed->getErrorOutput());
            $leased = $this->worker($user->id, $leaseNotification, (string) $subscriptions[0]->id, $files, false);
            $leased->run();
            $this->assertCount(2, $this->attempts($files['log']));
            BrowserPushDelivery::query()->where('notification_id', $leaseNotification)
                ->update(['lease_expires_at' => now()->subSecond()]);
            $recovered = $this->worker($user->id, $leaseNotification, (string) $subscriptions[0]->id, $files, false);
            $recovered->run();
            $this->assertCount(3, $this->attempts($files['log']));
            $this->assertSame('sent', BrowserPushDelivery::query()->where('notification_id', $leaseNotification)->value('status'));
        } finally {
            $this->cleanup($user, $subscriptions, $files);
        }
    }

    private function fixture(int $count): array
    {
        $role = Role::firstOrCreate(['name' => 'team_member']);
        $user = User::factory()->create(['role_id' => $role->id, 'active' => true]);
        $subscriptions = [];
        foreach (range(1, $count) as $index) {
            $endpoint = "https://push.example.test/r2b5q/{$user->id}/{$index}";
            $subscription = new BrowserPushSubscription;
            $subscription->forceFill([
                'user_id' => $user->id, 'endpoint' => $endpoint,
                'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
                'public_key' => "key-{$index}", 'auth_secret' => "secret-{$index}",
            ])->save();
            $subscriptions[] = $subscription;
        }

        return [$user, $subscriptions];
    }

    private function files(): array
    {
        $token = bin2hex(random_bytes(8));

        return ['log' => sys_get_temp_dir()."/r2b5q-push-log-{$token}", 'ready' => sys_get_temp_dir()."/r2b5q-push-ready-{$token}", 'release' => sys_get_temp_dir()."/r2b5q-push-release-{$token}"];
    }

    private function worker(int $user, string $notification, string $subscription, array $files, bool $barrier, string $mode = 'success'): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/browser_push_race_worker.php'), (string) $user, $notification, $subscription, $files['log'], $barrier ? $files['ready'] : '-', $barrier ? $files['release'] : '-', $mode], base_path(), timeout: 30);
    }

    private function waitReady(Process $worker, string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($file) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($file, $worker->getErrorOutput().$worker->getOutput());
    }

    private function attempts(string $file): array
    {
        if (! file_exists($file)) {
            return [];
        }

        return array_map('intval', array_values(array_filter(file($file, FILE_IGNORE_NEW_LINES))));
    }

    private function cleanup(User $user, array $subscriptions, array $files): void
    {
        BrowserPushDelivery::query()->whereIn('browser_push_subscription_id', array_map(fn ($subscription) => $subscription->id, $subscriptions))->delete();
        BrowserPushSubscription::query()->where('user_id', $user->id)->delete();
        $roleId = $user->role_id;
        $user->forceDelete();
        if (! User::query()->where('role_id', $roleId)->exists()) {
            Role::query()->whereKey($roleId)->delete();
        }
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || preg_match('/^task_management_phase28_r2b5q_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires an isolated R2B.5Q MariaDB database.');
        }
    }
}
