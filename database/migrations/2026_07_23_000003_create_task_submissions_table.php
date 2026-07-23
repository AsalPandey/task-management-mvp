<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('revision_cycle_id')
                ->nullable()
                ->index()
                ->constrained('task_revision_cycles')
                ->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->text('submission_note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'submitted_at'], 'task_submissions_history_idx');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_submissions');
    }
};
