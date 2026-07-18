<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('task_events', function (Blueprint $table) use ($driver) {
            $table->id();

            $eventUid = $table->char('event_uid', 26);

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $eventUid->charset('ascii')->collation('ascii_bin');
            }

            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32);
            $table->string('correlation_id', 64)->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique('event_uid');
            $table->unique(['task_id', 'sequence']);
            $table->index(['task_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index('correlation_id');
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
    }
};
