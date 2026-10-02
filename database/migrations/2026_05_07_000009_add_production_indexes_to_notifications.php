<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_user_read_created_index');
        });

        Schema::table('task_histories', function (Blueprint $table) {
            $table->index(['task_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // MariaDB may replace the original FK index when a covering index is added.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! Schema::hasIndex('task_histories', 'task_histories_task_id_foreign')) {
                Schema::table('task_histories', fn (Blueprint $table) => $table->index('task_id', 'task_histories_task_id_foreign'));
            }
            if (! Schema::hasIndex('task_histories', 'task_histories_user_id_foreign')) {
                Schema::table('task_histories', fn (Blueprint $table) => $table->index('user_id', 'task_histories_user_id_foreign'));
            }
        }

        Schema::table('task_histories', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_read_created_index');
        });
    }
};
