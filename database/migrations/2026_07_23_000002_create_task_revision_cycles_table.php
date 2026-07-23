<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_revision_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->text('formal_feedback')->nullable();
            $table->date('revision_due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('origin', 32)->nullable();
            $table->string('reopen_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'cycle_number']);
            $table->index(['task_id', 'resolved_at'], 'task_revision_unresolved_idx');
            $table->index('revision_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_revision_cycles');
    }
};
