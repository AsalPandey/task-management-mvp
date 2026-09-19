<?php

use App\Support\UlidGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ulids = app(UlidGenerator::class);

        DB::table('tasks')->whereNull('task_uid')->orderBy('id')->eachById(function ($task) use ($ulids): void {
            do {
                $uid = $ulids->generate();
            } while (DB::table('tasks')->where('task_uid', $uid)->exists());

            DB::table('tasks')->where('id', $task->id)->whereNull('task_uid')->update(['task_uid' => $uid]);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $taskUid = $table->char('task_uid', 26)->nullable(false)->change();
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $taskUid->charset('ascii')->collation('ascii_bin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $taskUid = $table->char('task_uid', 26)->nullable()->change();
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $taskUid->charset('ascii')->collation('ascii_bin');
            }
        });
    }
};
