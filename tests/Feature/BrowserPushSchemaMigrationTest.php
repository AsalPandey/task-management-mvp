<?php

namespace Tests\Feature;

use App\Models\BrowserPushDelivery;
use App\Models\BrowserPushSubscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowserPushSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_and_delivery_rows_are_device_scoped_unique_and_cascade_with_user(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://push.example.test/device/schema';
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => str_repeat('p', 80),
            'auth_secret' => str_repeat('a', 20),
            'content_encoding' => 'aes128gcm',
        ])->save();
        BrowserPushDelivery::query()->create([
            'browser_push_subscription_id' => $subscription->id,
            'notification_id' => (string) Str::uuid(),
        ]);

        $this->assertDatabaseCount('browser_push_subscriptions', 1);
        $this->assertDatabaseCount('browser_push_deliveries', 1);

        $user->forceDelete();

        $this->assertDatabaseCount('browser_push_subscriptions', 0);
        $this->assertDatabaseCount('browser_push_deliveries', 0);
    }

    public function test_additive_upgrade_preserves_existing_rows_and_creates_both_push_tables(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phase3b-upgrade-');
        $this->assertNotFalse($path);

        Config::set('database.connections.phase3b_upgrade', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        try {
            Schema::connection('phase3b_upgrade')->create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
            DB::connection('phase3b_upgrade')->table('users')->insert([
                'id' => 42,
                'name' => 'Preserved legacy user',
            ]);

            $exit = Artisan::call('migrate', [
                '--database' => 'phase3b_upgrade',
                '--path' => [
                    database_path('migrations/2026_07_28_000001_create_browser_push_subscriptions_table.php'),
                    database_path('migrations/2026_07_28_000002_create_browser_push_deliveries_table.php'),
                ],
                '--realpath' => true,
                '--force' => true,
            ]);

            $this->assertSame(0, $exit);
            $this->assertTrue(Schema::connection('phase3b_upgrade')->hasTable('browser_push_subscriptions'));
            $this->assertTrue(Schema::connection('phase3b_upgrade')->hasTable('browser_push_deliveries'));
            $this->assertSame(
                'Preserved legacy user',
                DB::connection('phase3b_upgrade')->table('users')->where('id', 42)->value('name'),
            );
        } finally {
            DB::purge('phase3b_upgrade');
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
