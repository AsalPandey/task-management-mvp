<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type', 40);
            $table->char('deadline_generation', 64);
            $table->unsignedBigInteger('recipient_id');
            $table->uuid('notification_id')->unique();
            $table->string('status', 24)->default('claimed');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('claimed_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_failure_code', 80)->nullable();
            $table->timestamps();

            $table->unique(
                ['task_id', 'notification_type', 'deadline_generation', 'recipient_id'],
                'task_notification_logical_delivery_unique',
            );
            $table->index(['notification_type', 'status', 'task_id'], 'task_notification_scheduler_index');
        });

        Schema::table('browser_push_deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0)->after('status');
            $table->timestamp('claimed_at')->nullable()->after('attempt_count');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
            $table->timestamp('next_attempt_at')->nullable()->after('lease_expires_at');
            $table->timestamp('delivered_at')->nullable()->after('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('browser_push_deliveries', function (Blueprint $table): void {
            $table->dropColumn([
                'attempt_count',
                'claimed_at',
                'lease_expires_at',
                'next_attempt_at',
                'delivered_at',
            ]);
        });
        Schema::dropIfExists('task_notification_deliveries');
    }
};
