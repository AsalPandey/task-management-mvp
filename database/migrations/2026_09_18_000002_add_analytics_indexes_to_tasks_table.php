<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index('created_at', 'tasks_created_at_index');
            $table->index(['status', 'completed_at'], 'tasks_status_completed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_created_at_index');
            $table->dropIndex('tasks_status_completed_at_index');
        });
    }
};
