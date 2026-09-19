<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('id');
        });

        DB::table('tasks')
            ->whereNull('lock_version')
            ->orWhere('lock_version', '<', 1)
            ->update(['lock_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
