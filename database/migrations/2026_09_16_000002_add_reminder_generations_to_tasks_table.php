<?php

use App\ValueObjects\TaskDeadlineGeneration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->char('deadline_reminder_generation', 64)
                ->nullable()
                ->after('deadline_reminder_sent_at');
            $table->char('overdue_notification_generation', 64)
                ->nullable()
                ->after('overdue_notification_sent_at');
        });

        DB::table('tasks')
            ->select([
                'id',
                'status',
                'assignee_id',
                'reviewer_id',
                'due_date',
                'execution_due_date',
                'review_due_date',
                'revision_due_date',
                'active_revision_cycle_id',
                'deadline_reminder_sent_at',
                'overdue_notification_sent_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $generation = $this->generationFor($task);
                    if ($generation === null) {
                        continue;
                    }

                    DB::table('tasks')->where('id', $task->id)->update([
                        'deadline_reminder_generation' => $task->deadline_reminder_sent_at ? $generation : null,
                        'overdue_notification_generation' => $task->overdue_notification_sent_at ? $generation : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'deadline_reminder_generation',
                'overdue_notification_generation',
            ]);
        });
    }

    private function generationFor(object $task): ?string
    {
        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            return null;
        }

        if (in_array($task->status, ['submitted', 'in_review'], true)) {
            $kind = 'review';
            $deadline = $task->review_due_date;
            $responsibleUserId = $task->reviewer_id;
            $cycleId = $task->active_revision_cycle_id;
        } elseif ($task->status === 'revision_requested'
            || ($task->status === 'in_progress' && $task->active_revision_cycle_id && $task->revision_due_date)) {
            $kind = 'revision';
            $deadline = $task->revision_due_date;
            $responsibleUserId = $task->assignee_id;
            $cycleId = $task->active_revision_cycle_id;
        } else {
            $kind = 'execution';
            $deadline = $task->execution_due_date ?: $task->due_date;
            $responsibleUserId = $task->assignee_id;
            $cycleId = null;
        }

        if (! $deadline) {
            return null;
        }

        return TaskDeadlineGeneration::fingerprintFor(
            $kind,
            substr((string) $deadline, 0, 10),
            $responsibleUserId === null ? null : (int) $responsibleUserId,
            $cycleId === null ? null : (int) $cycleId,
        );
    }
};
