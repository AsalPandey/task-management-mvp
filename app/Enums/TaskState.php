<?php

namespace App\Enums;

enum TaskState: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case RevisionRequested = 'revision_requested';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Started',
            self::InProgress => 'In Progress',
            self::OnHold => 'On Hold',
            self::Submitted => 'Submitted',
            self::InReview => 'In Review',
            self::RevisionRequested => 'Revision Requested',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isExecutionState(): bool
    {
        return in_array($this, [
            self::NotStarted,
            self::InProgress,
            self::OnHold,
            self::RevisionRequested,
        ], true);
    }

    public function isReviewState(): bool
    {
        return in_array($this, [self::Submitted, self::InReview], true);
    }

    public function allowsProgressUpdates(): bool
    {
        return $this === self::InProgress;
    }

    public function hasActiveDeadline(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $state) {
            $options[$state->value] = $state->label();
        }

        return $options;
    }
}
