<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('timezone');
            $table->timestamp('last_login_at')->nullable()->after('notification_preferences');
            $table->index(['role_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id', 'active']);
            $table->dropColumn(['notification_preferences', 'last_login_at']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
