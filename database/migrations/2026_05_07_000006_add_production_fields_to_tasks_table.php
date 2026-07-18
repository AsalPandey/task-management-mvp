<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('original_task_id')->nullable()->after('id')->index();
            $table->foreignId('project_id')->nullable()->after('original_task_id')->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('deadline_reminder_sent_at')->nullable()->after('comments');
            $table->timestamp('overdue_notification_sent_at')->nullable()->after('deadline_reminder_sent_at');
            $table->index(['project_id', 'assignee_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'assignee_id', 'status']);
            $table->dropIndex(['due_date', 'status']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('original_task_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['deadline_reminder_sent_at', 'overdue_notification_sent_at']);
        });
    }
};
