<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    public function test_endpoint_is_authenticated_private_opaque_and_scoped(): void
    {
        $this->seed();
        $this->getJson('/client/freshness')->assertUnauthorized();
        $member = $this->actor('team_member');
        $other = $this->actor('team_member');
        $manager = $this->actor('manager');
        $project = Project::create(['name' => 'Private project', 'project_manager_id' => $manager->id]);
        $task = Task::create(['title' => 'Private title', 'assignee_id' => $other->id, 'project_id' => $project->id, 'created_by' => $manager->id, 'status' => 'not_started', 'priority' => 'High']);
        $before = $this->actingAs($member)->getJson('/client/freshness')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $before->assertJsonStructure(['version', 'user']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $before->json('version'));
        $this->assertStringNotContainsString('Private title', $before->getContent());
        $task->forceFill(['title' => 'Unrelated mutation', 'lock_version' => 2])->save();
        $other->update(['name' => 'Unrelated account change']);
        $project->update(['name' => 'Unrelated project change']);
        $this->assertSame($before->json('version'), $this->getJson('/client/freshness')->assertOk()->json('version'));
        $task->forceFill(['assignee_id' => $member->id, 'lock_version' => 3])->save();
        $this->assertNotSame($before->json('version'), $this->getJson('/client/freshness')->assertOk()->json('version'));
    }

    public function test_same_second_task_versions_project_memberships_and_account_edits_change_snapshot(): void
    {
        $this->seed();
        $manager = $this->actor('manager');
        $member = $this->actor('team_member');
        $project = Project::create(['name' => 'Visible project', 'project_manager_id' => $manager->id]);
        $task = Task::create(['title' => 'Visible task', 'assignee_id' => $member->id, 'project_id' => $project->id, 'created_by' => $manager->id, 'status' => 'not_started', 'priority' => 'High']);
        $this->actingAs($manager);
        $version = fn () => $this->getJson('/client/freshness')->assertOk()->json('version');
        $last = $version();
        foreach ([fn () => $task->forceFill(['lock_version' => 2])->save(), fn () => $project->update(['name' => 'Edited project']),
            fn () => $project->members()->attach($member), fn () => $member->update(['name' => 'Edited member']),
            fn () => $task->delete()] as $mutate) {
            $mutate();
            $next = $version();
            $this->assertNotSame($last, $next);
            $last = $next;
        }
    }

    public function test_endpoint_does_not_hydrate_business_models_and_queries_are_bounded(): void
    {
        $this->seed();
        $manager = $this->actor('manager');
        $retrieved = 0;
        foreach ([Task::class, Project::class] as $model) {
            $model::retrieved(function () use (&$retrieved) {
                $retrieved++;
            });
        }
        DB::enableQueryLog();
        $this->actingAs($manager)->getJson('/client/freshness')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(0, $retrieved);
        $this->assertLessThanOrEqual(12, count($queries));
    }
}
