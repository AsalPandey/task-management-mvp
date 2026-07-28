<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('browser_push_subscription_id')
                ->constrained('browser_push_subscriptions')
                ->cascadeOnDelete();
            $table->uuid('notification_id');
            $table->string('status', 24)->default('pending');
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->unique(
                ['browser_push_subscription_id', 'notification_id'],
                'browser_push_delivery_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_push_deliveries');
    }
};
