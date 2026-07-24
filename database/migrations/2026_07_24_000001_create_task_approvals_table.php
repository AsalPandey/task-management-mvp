<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_id')->constrained('task_submissions')->restrictOnDelete();
            $table->foreignId('revision_cycle_id')->nullable()->constrained('task_revision_cycles')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->boolean('is_override')->default(false);
            $table->text('approval_comment')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'approved_at']);
            $table->index(['approved_by', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_approvals');
    }
};
