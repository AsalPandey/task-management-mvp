<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->foreignId('completed_task_id')->nullable()->after('task_id')->constrained('completed_tasks')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('completed_task_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('original_task_id')->nullable()->after('project_id')->index();
            $table->string('task_title')->nullable()->after('original_task_id');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropIndex(['original_task_id']);
            $table->dropConstrainedForeignId('completed_task_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['original_task_id', 'task_title']);
        });
    }
};
