<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\R61ProcessHarness;
use Tests\TestCase;

class R61AccountMariaDbConcurrencyTest extends TestCase
{
    use R61ProcessHarness;

    public function test_demotion_and_deactivation_deny_inflight_writers_twice_each(): void
    {
        foreach (['demotion', 'deactivation'] as $revocation) {
            foreach (['update', 'activate', 'deactivate', 'delete'] as $action) {
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    $admin = $this->user('manager');
                    $actor = $this->user('manager');
                    $target = $this->user('team_member');
                    $target->update(['active' => $action !== 'activate']);
                    $before = $target->fresh()->getAttributes();
                    $method = $action === 'update' ? 'PUT' : ($action === 'delete' ? 'DELETE' : 'POST');
                    $path = '/team-management/'.$target->id.(in_array($action, ['activate', 'deactivate']) ? '/'.$action : '');
                    $stale = $this->start(['actor' => $actor->id, 'target' => $target->id, 'pause' => 'target-binding',
                        'method' => $method, 'path' => $path, 'data' => $action === 'update'
                            ? ['name' => 'Unauthorized promotion', 'email' => $target->email, 'role_id' => $admin->role_id] : []]);
                    $this->ready($stale);
                    $revoke = $this->start(['actor' => $admin->id, 'method' => $revocation === 'demotion' ? 'PUT' : 'POST',
                        'path' => '/team-management/'.$actor->id.($revocation === 'deactivation' ? '/deactivate' : ''),
                        'data' => $revocation === 'demotion' ? ['name' => $actor->name, 'email' => $actor->email,
                            'role_id' => Role::where('name', 'team_member')->value('id')] : []]);
                    $this->assertSame(200, $this->finish($revoke)['status']);
                    $notices = DB::table('notifications')->count();
                    file_put_contents($stale[1]['release'], 'go');
                    $this->assertSame(403, $this->finish($stale)['status']);
                    $this->assertSame($before, User::withTrashed()->findOrFail($target->id)->getAttributes());
                    $this->assertSame($notices, DB::table('notifications')->count());
                }
            }
        }
    }

    public function test_authorized_concurrent_manager_updates_serialize_without_deadlock(): void
    {
        $a = $this->user('manager');
        $b = $this->user('manager');
        $target = $this->user('team_member');
        $one = $this->start(['actor' => $a->id, 'pause' => 'locked-accounts', 'method' => 'PUT',
            'path' => '/team-management/'.$target->id, 'data' => ['name' => 'First', 'email' => $target->email]]);
        $this->ready($one);
        $two = $this->start(['actor' => $b->id, 'method' => 'PUT', 'path' => '/team-management/'.$target->id,
            'data' => ['name' => 'Second', 'email' => $target->email]]);
        $until = microtime(true) + 15;
        do {
            $wait = str_contains(DB::select('SHOW ENGINE INNODB STATUS')[0]->Status, 'LOCK WAIT');
            if ($wait) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->assertTrue($wait, 'Actual lock wait required.');
        file_put_contents($one[1]['release'], 'go');
        $this->assertSame(200, $this->finish($one)['status']);
        $this->assertSame(200, $this->finish($two)['status']);
        $this->assertSame('Second', $target->fresh()->name);
    }

    public function test_revoked_actor_cannot_create_an_account_after_validation(): void
    {
        foreach (['demotion', 'deactivation'] as $revocation) {
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $admin = $this->user('manager');
                $actor = $this->user('manager');
                $email = 'stale-create-'.bin2hex(random_bytes(6)).'@example.invalid';
                $stale = $this->start(['actor' => $actor->id, 'pause' => 'email-validation',
                    'method' => 'POST', 'path' => '/team-management', 'data' => [
                        'name' => 'Denied creation', 'email' => $email, 'password' => 'R61-stale-password-123!',
                        'role_id' => $admin->role_id,
                    ]]);
                $this->ready($stale);
                $revoke = $this->start(['actor' => $admin->id, 'method' => $revocation === 'demotion' ? 'PUT' : 'POST',
                    'path' => '/team-management/'.$actor->id.($revocation === 'deactivation' ? '/deactivate' : ''),
                    'data' => $revocation === 'demotion' ? ['name' => $actor->name, 'email' => $actor->email,
                        'role_id' => Role::where('name', 'team_member')->value('id')] : []]);
                $this->assertSame(200, $this->finish($revoke)['status']);
                $notices = DB::table('notifications')->count();
                file_put_contents($stale[1]['release'], 'go');
                $this->assertSame(403, $this->finish($stale)['status']);
                $this->assertFalse(User::withTrashed()->where('email', $email)->exists());
                $this->assertSame($notices, DB::table('notifications')->count());
            }
        }
    }
}
