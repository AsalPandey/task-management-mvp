<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // JSON-encoded Unicode snapshots (including old/new values) can exceed TEXT.
        Schema::table('task_histories', fn (Blueprint $table) => $table->longText('changes')->nullable()->change());
        Schema::table('notifications', fn (Blueprint $table) => $table->longText('data')->change());
        Schema::table('tasks', function (Blueprint $table): void {
            $table->text('cancellation_reason')->nullable()->change();
            $table->json('legacy_completion_provenance')->nullable();
        });
        Schema::table('task_revision_cycles', fn (Blueprint $table) => $table->text('reopen_reason')->nullable()->change());
        DB::table('tasks')->where('status', 'completed')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('task_approvals')->whereColumn('task_approvals.task_id', 'tasks.id');
        })->orderBy('id')->chunkById(200, function ($tasks): void {
            foreach ($tasks as $task) {
                DB::table('tasks')->where('id', $task->id)->update(['legacy_completion_provenance' => json_encode([
                    'source' => 'pre_r42_completed_snapshot', 'approval_evidence' => 'unavailable',
                    'observed_status' => $task->status, 'retained_completed_at' => $task->completed_at,
                    'retained_completed_by' => $task->completed_by, 'recorded_at' => now()->toAtomString(),
                ], JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        // Refuse lossy narrowing before any DDL. Empty disposable reset remains supported.
        $characters = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $historyBytes = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(changes AS BLOB))' : 'LENGTH(changes)';
        $notificationBytes = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(data AS BLOB))' : 'LENGTH(data)';
        if (DB::table('tasks')->whereRaw($characters.'(cancellation_reason) > 1000')->exists()
            || DB::table('task_revision_cycles')->whereRaw($characters.'(reopen_reason) > 1000')->exists()
            || DB::table('task_histories')->whereRaw($historyBytes.' > 65535')->exists()
            || DB::table('notifications')->whereRaw($notificationBytes.' > 65535')->exists()) {
            throw new RuntimeException('Cannot narrow text storage without losing retained business history. Export/reconcile data before rollback.');
        }
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('cancellation_reason', 1000)->nullable()->change();
            $table->dropColumn('legacy_completion_provenance');
        });
        Schema::table('task_revision_cycles', fn (Blueprint $table) => $table->string('reopen_reason', 1000)->nullable()->change());
        Schema::table('task_histories', fn (Blueprint $table) => $table->text('changes')->nullable()->change());
        Schema::table('notifications', fn (Blueprint $table) => $table->text('data')->change());
    }
};
