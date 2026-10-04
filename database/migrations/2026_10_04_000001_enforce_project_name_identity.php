<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collation = DB::getDriverName() === 'mysql' ? 'utf8mb4_unicode_ci' : 'NOCASE';
        $identity = 'TRIM(name) COLLATE '.$collation;
        if (DB::table('projects')->selectRaw('COUNT(*) as total')
            ->groupByRaw($identity)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Project name identity conflicts exist, including deleted projects. Reconcile explicitly before retrying this migration; no records were renamed.');
        }
        if (! Schema::hasColumn('projects', 'name_identity')) {
            Schema::table('projects', function (Blueprint $table) use ($collation) {
                $table->string('name_identity', 255)->collation($collation)->virtualAs('TRIM(name)');
            });
        }
        if (! collect(Schema::getIndexes('projects'))->contains('name', 'projects_name_identity_unique')) {
            Schema::table('projects', fn (Blueprint $table) => $table->unique('name_identity', 'projects_name_identity_unique'));
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_name_identity_unique');
            $table->dropColumn('name_identity');
        });
    }
};
