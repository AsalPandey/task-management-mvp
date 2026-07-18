<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completed_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('original_task_id')->nullable()->after('id')->index();
            $table->foreignId('project_id')->nullable()->after('original_task_id')->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('reverted_by')->nullable()->after('reverted')->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable()->after('reverted_by');
            $table->index(['project_id', 'assignee_id', 'completed_at']);
            $table->index(['completed_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('completed_tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'assignee_id', 'completed_at']);
            $table->dropIndex(['completed_at', 'status']);
            $table->dropColumn('original_task_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn('reverted_at');
        });
    }
};
