<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_notification_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('task_version');
            // Immutable destination IDs survive account deletion for safe termination.
            // User FKs would acquire account locks after the business writer's project/task locks.
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('transition', 64);
            $table->string('responsibility', 16);
            $table->string('action', 128);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'task_version', 'recipient_id', 'transition'], 'workflow_notice_identity_unique');
            $table->index(['status', 'available_at', 'id'], 'workflow_notice_pending_index');
            $table->index(['status', 'finished_at'], 'workflow_notice_cleanup_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_notification_intents') && DB::table('workflow_notification_intents')->where('status', 'pending')->exists()) {
            throw new RuntimeException('Drain pending required workflow notices before rolling back durable dispatch storage.');
        }
        Schema::dropIfExists('workflow_notification_intents');
    }
};
