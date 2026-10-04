<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class R52SchemaMigrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (! Schema::hasTable('roles')) {
            $this->artisan('migrate:fresh')->run();
        }
    }

    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable('migrations')) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_upgrade_preflight_preserves_conflicts_until_explicit_fixture_reconciliation(): void
    {
        $migration = require database_path('migrations/2026_10_04_000001_enforce_project_name_identity.php');
        $migration->down();
        $one = Project::query()->create(['name' => 'R52 Identity', 'status' => 'archived']);
        $one->delete();
        $two = Project::query()->create(['name' => ' r52 identity ', 'status' => 'active']);
        try {
            $migration->up();
            $this->fail('Expected conflict preflight to stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicts exist', $exception->getMessage());
        }
        $this->assertSame('R52 Identity', Project::withTrashed()->findOrFail($one->id)->name);
        $this->assertSame(' r52 identity ', $two->fresh()->name);
        $this->assertFalse(Schema::hasColumn('projects', 'name_identity'));
        // Only this disposable duplicate is explicitly reconciled; the migration never renames data.
        $two->forceDelete();
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('projects', 'name_identity'));
        $this->assertTrue(Project::withTrashed()->findOrFail($one->id)->trashed());
        $this->expectException(UniqueConstraintViolationException::class);
        Project::query()->create(['name' => 'r52 identity', 'status' => 'active']);
    }

    public function test_new_schema_can_rollback_and_remigrate_without_changing_existing_projects(): void
    {
        $project = Project::query()->create(['name' => 'Reserved upgrade name', 'status' => 'active']);
        $identity = require database_path('migrations/2026_10_04_000001_enforce_project_name_identity.php');
        $dispatch = require database_path('migrations/2026_10_04_000002_create_workflow_notification_intents.php');
        $dispatch->down();
        $identity->down();
        $this->assertFalse(Schema::hasColumn('projects', 'name_identity'));
        $identity->up();
        $dispatch->up();
        $this->assertSame('Reserved upgrade name', $project->fresh()->name);
        $this->assertSame($project->id, $project->fresh()->id);
        $this->assertTrue(Schema::hasTable('workflow_notification_intents'));
    }
}
