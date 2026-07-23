<?php

namespace App\Support;

use App\Enums\TaskState;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TaskStateCompatibility
{
    /**
     * Temporary input aliases retained until every legacy task form sends machine values.
     *
     * @var array<string, string>
     */
    private const LEGACY_LABELS = [
        'Not Started' => TaskState::NotStarted->value,
        'In Progress' => TaskState::InProgress->value,
        'On Hold' => TaskState::OnHold->value,
        'Completed' => TaskState::Completed->value,
    ];

    /**
     * @return array<int, string>
     */
    public static function genericStates(): array
    {
        return [
            TaskState::NotStarted->value,
            TaskState::InProgress->value,
            TaskState::OnHold->value,
            TaskState::Completed->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function genericOptions(): array
    {
        return collect(self::genericStates())
            ->mapWithKeys(fn (string $value) => [$value => TaskState::from($value)->label()])
            ->all();
    }

    public static function normalizeGenericInput(string $value, string $field = 'status'): string
    {
        $normalized = self::normalizeLegacyLabel($value);

        if (! in_array($normalized, self::genericStates(), true)) {
            throw ValidationException::withMessages([
                $field => 'This workflow state requires a dedicated transition action.',
            ]);
        }

        return $normalized;
    }

    public static function normalizeLegacyLabel(string $value): string
    {
        return self::LEGACY_LABELS[$value] ?? $value;
    }

    public static function requiresDedicatedExecutionTransition(TaskState $from, TaskState $to): bool
    {
        return in_array(
            [$from, $to],
            [
                [TaskState::NotStarted, TaskState::InProgress],
                [TaskState::InProgress, TaskState::OnHold],
                [TaskState::OnHold, TaskState::InProgress],
            ],
            true,
        );
    }

    public static function requiresDedicatedTransition(TaskState $from, TaskState $to): bool
    {
        return self::requiresDedicatedExecutionTransition($from, $to)
            || in_array(
                [$from, $to],
                [
                    [TaskState::InProgress, TaskState::Submitted],
                    [TaskState::Submitted, TaskState::InReview],
                ],
                true,
            );
    }

    public static function assertGenericTransitionAllowed(
        TaskState $from,
        TaskState $to,
        string $field = 'status',
    ): void {
        if (self::requiresDedicatedTransition($from, $to)) {
            throw ValidationException::withMessages([
                $field => 'Use the dedicated task workflow action for this state change.',
            ]);
        }
    }

    public static function normalizeForStorage(string|TaskState $value): string
    {
        if ($value instanceof TaskState) {
            return $value->value;
        }

        $normalized = self::LEGACY_LABELS[$value] ?? $value;

        if (TaskState::tryFrom($normalized) === null) {
            throw new InvalidArgumentException("Unknown task state [{$value}].");
        }

        return $normalized;
    }

    public static function label(string|TaskState $value): string
    {
        if ($value instanceof TaskState) {
            return $value->label();
        }

        if (isset(self::LEGACY_LABELS[$value])) {
            return $value;
        }

        return TaskState::from($value)->label();
    }
}
