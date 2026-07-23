<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const TO_MACHINE = [
        'Not Started' => 'not_started',
        'In Progress' => 'in_progress',
        'On Hold' => 'on_hold',
        'Completed' => 'completed',
    ];

    public function up(): void
    {
        $this->convert(self::TO_MACHINE, 'machine');
        $this->setDefault('not_started');
    }

    public function down(): void
    {
        $this->convert(array_flip(self::TO_MACHINE), 'display');
        $this->setDefault('Not Started');
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function convert(array $mapping, string $target): void
    {
        DB::transaction(function () use ($mapping, $target) {
            $knownValues = array_unique(array_merge(array_keys($mapping), array_values($mapping)));
            $unknown = DB::table('tasks')
                ->select('status')
                ->distinct()
                ->pluck('status')
                ->reject(fn ($status) => is_string($status) && in_array($status, $knownValues, true))
                ->values();

            if ($unknown->isNotEmpty()) {
                throw new RuntimeException(
                    "Cannot convert task statuses to {$target} values; unknown statuses: ".$unknown->join(', '),
                );
            }

            foreach ($mapping as $from => $to) {
                DB::table('tasks')
                    ->where('status', $from)
                    ->update(['status' => $to]);
            }
        });
    }

    private function setDefault(string $default): void
    {
        Schema::table('tasks', function (Blueprint $table) use ($default) {
            $table->string('status')->default($default)->change();
        });
    }
};
