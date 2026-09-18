<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final readonly class TaskDeadlineGeneration
{
    public function __construct(
        public string $kind,
        public Carbon $deadline,
        public ?int $responsibleUserId,
        public ?int $workflowCycleId,
    ) {}

    public function fingerprint(): string
    {
        return self::fingerprintFor(
            $this->kind,
            $this->deadline->toDateString(),
            $this->responsibleUserId,
            $this->workflowCycleId,
        );
    }

    public function isOverdue(?CarbonInterface $at = null): bool
    {
        $timezone = config('app.timezone');
        $localDate = $at
            ? CarbonImmutable::instance($at)->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        return $localDate->isAfter($this->deadline->setTimezone($timezone)->startOfDay());
    }

    public static function fingerprintFor(
        string $kind,
        string $deadline,
        ?int $responsibleUserId,
        ?int $workflowCycleId,
    ): string {
        return hash('sha256', implode('|', [
            'v1',
            $kind,
            $deadline,
            (string) ($responsibleUserId ?? 0),
            (string) ($workflowCycleId ?? 0),
        ]));
    }
}
