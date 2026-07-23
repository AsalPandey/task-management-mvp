<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('reviewer_id')
                ->nullable()
                ->after('assignee_id')
                ->index()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable()->after('overdue_notification_sent_at');
            $table->timestamp('submitted_at')->nullable()->after('started_at');
            $table->timestamp('review_started_at')->nullable()->after('submitted_at');
            $table->timestamp('approved_at')->nullable()->after('review_started_at');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->date('execution_due_date')->nullable()->after('due_date');
            $table->date('review_due_date')->nullable()->after('execution_due_date');
            $table->date('revision_due_date')->nullable()->after('review_due_date');

            $table->timestamp('held_at')->nullable()->after('approved_by');
            $table->foreignId('held_by')
                ->nullable()
                ->after('held_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('hold_reason', 1000)->nullable()->after('held_by');

            $table->timestamp('cancelled_at')->nullable()->after('hold_reason');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('cancellation_reason', 1000)->nullable()->after('cancelled_by');

            $table->unsignedInteger('revision_count')->default(0)->after('cancellation_reason');
            $table->unsignedBigInteger('active_revision_cycle_id')
                ->nullable()
                ->after('revision_count')
                ->index();

            $table->string('importance_level', 32)->nullable()->after('priority');
            $table->string('manual_urgency_level', 32)->nullable()->after('importance_level');
            $table->unsignedSmallInteger('calculated_urgency_score')->nullable()->after('manual_urgency_level');
            $table->string('effective_urgency_level', 32)->nullable()->after('calculated_urgency_score');
            $table->string('priority_tier', 32)->nullable()->after('effective_urgency_level');
            $table->timestamp('priority_calculated_at')->nullable()->after('priority_tier');
            $table->string('priority_override_reason', 1000)->nullable()->after('priority_calculated_at');

            $table->index(['status', 'reviewer_id'], 'tasks_status_reviewer_idx');
            $table->index(['status', 'review_due_date'], 'tasks_status_review_due_idx');
            $table->index(['status', 'revision_due_date'], 'tasks_status_revision_due_idx');
        });

        DB::table('tasks')
            ->whereNull('execution_due_date')
            ->whereNotNull('due_date')
            ->update(['execution_due_date' => DB::raw('due_date')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_status_reviewer_idx');
            $table->dropIndex('tasks_status_review_due_idx');
            $table->dropIndex('tasks_status_revision_due_idx');
            $table->dropIndex(['active_revision_cycle_id']);

            $table->dropForeign(['reviewer_id']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['held_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['reviewer_id']);

            $table->dropColumn([
                'reviewer_id',
                'started_at',
                'submitted_at',
                'review_started_at',
                'approved_at',
                'approved_by',
                'execution_due_date',
                'review_due_date',
                'revision_due_date',
                'held_at',
                'held_by',
                'hold_reason',
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'revision_count',
                'active_revision_cycle_id',
                'importance_level',
                'manual_urgency_level',
                'calculated_urgency_score',
                'effective_urgency_level',
                'priority_tier',
                'priority_calculated_at',
                'priority_override_reason',
            ]);
        });
    }
};
