<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('tasks', function (Blueprint $table) use ($driver) {
            $taskUid = $table->char('task_uid', 26)->nullable()->after('id');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $taskUid->charset('ascii')->collation('ascii_bin');
            }

            $table->timestamp('completed_at')->nullable()->after('overdue_notification_sent_at');
            $table->foreignId('completed_by')
                ->nullable()
                ->after('completed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->unique('task_uid');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['task_uid']);
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['task_uid', 'completed_at']);
        });
    }
};
