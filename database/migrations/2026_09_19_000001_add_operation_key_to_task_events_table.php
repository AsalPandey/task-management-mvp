<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('task_events', function (Blueprint $table) use ($driver) {
            $operationKey = $table->char('operation_key', 64)->nullable()->after('correlation_id');
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $operationKey->charset('ascii')->collation('ascii_bin');
            }
            $table->unique('operation_key', 'task_events_operation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('task_events', function (Blueprint $table) {
            $table->dropUnique('task_events_operation_key_unique');
            $table->dropColumn('operation_key');
        });
    }
};
