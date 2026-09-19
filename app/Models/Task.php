<?php

namespace App\Models;

use App\Enums\TaskState;
use App\Support\TaskStateCompatibility;
use App\Support\UlidGenerator;
use App\ValueObjects\TaskDeadlineGeneration;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $hidden = [
        'cancellation_reason',
        'hold_reason',
        'importance_level',
        'manual_urgency_level',
        'calculated_urgency_score',
        'effective_urgency_level',
        'priority_tier',
        'priority_calculated_at',
        'priority_override_reason',
    ];

    protected $fillable = [
        'project_id',
        'original_task_id',
        'title',
        'description',
        'assignee_id',
        'created_by',
        'assigned_by',
        'priority',
        'status',
        'progress',
        'start_date',
        'due_date',
        'comments',
        'deadline_reminder_sent_at',
        'overdue_notification_sent_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'execution_due_date' => 'date',
        'review_due_date' => 'date',
        'revision_due_date' => 'date',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'review_started_at' => 'datetime',
        'approved_at' => 'datetime',
        'held_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'revision_count' => 'integer',
        'calculated_urgency_score' => 'integer',
        'priority_calculated_at' => 'datetime',
        'completed_at' => 'datetime',
        'deadline_reminder_sent_at' => 'datetime',
        'overdue_notification_sent_at' => 'datetime',
    ];

    public const PRIORITIES = ['Low', 'Medium', 'High'];

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => TaskStateCompatibility::label($value),
            set: fn (string|TaskState $value) => TaskStateCompatibility::normalizeForStorage($value),
        );
    }

    public function machineState(): TaskState
    {
        return TaskState::from($this->getAttributes()['status']);
    }

    public function activeDeadline(): ?Carbon
    {
        return $this->activeDeadlineGeneration()?->deadline;
    }

    public function activeDeadlineGeneration(): ?TaskDeadlineGeneration
    {
        $state = $this->machineState();
        if ($state->isFinal()) {
            return null;
        }

        if (in_array($state, [TaskState::Submitted, TaskState::InReview], true)) {
            $kind = 'review';
            $deadline = $this->review_due_date;
            $responsibleUserId = $this->reviewer_id;
            $workflowCycleId = $this->active_revision_cycle_id;
        } elseif ($state === TaskState::RevisionRequested
            || ($state === TaskState::InProgress && $this->active_revision_cycle_id && $this->revision_due_date)) {
            $kind = 'revision';
            $deadline = $this->revision_due_date;
            $responsibleUserId = $this->assignee_id;
            $workflowCycleId = $this->active_revision_cycle_id;
        } else {
            $kind = 'execution';
            $deadline = $this->execution_due_date ?? $this->due_date;
            $responsibleUserId = $this->assignee_id;
            $workflowCycleId = null;
        }

        if (! $deadline) {
            return null;
        }

        return new TaskDeadlineGeneration(
            kind: $kind,
            deadline: $deadline->copy(),
            responsibleUserId: $responsibleUserId === null ? null : (int) $responsibleUserId,
            workflowCycleId: $workflowCycleId === null ? null : (int) $workflowCycleId,
        );
    }

    public function deadlineReminderWasSentForActiveGeneration(): bool
    {
        $generation = $this->activeDeadlineGeneration();

        return $generation !== null
            && hash_equals((string) $this->deadline_reminder_generation, $generation->fingerprint());
    }

    public function overdueNotificationWasSentForActiveGeneration(): bool
    {
        $generation = $this->activeDeadlineGeneration();

        return $generation !== null
            && hash_equals((string) $this->overdue_notification_generation, $generation->fingerprint());
    }

    public function markDeadlineReminderSentForActiveGeneration(): void
    {
        $generation = $this->activeDeadlineGeneration();
        if (! $generation) {
            return;
        }

        $this->forceFill([
            'deadline_reminder_sent_at' => now(),
            'deadline_reminder_generation' => $generation->fingerprint(),
        ])->save();
    }

    public function markOverdueNotificationSentForActiveGeneration(): void
    {
        $generation = $this->activeDeadlineGeneration();
        if (! $generation) {
            return;
        }

        $this->forceFill([
            'overdue_notification_sent_at' => now(),
            'overdue_notification_generation' => $generation->fingerprint(),
        ])->save();
    }

    public function statusLabel(): string
    {
        return $this->machineState()->label();
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            if (! $task->task_uid) {
                $task->task_uid = app(UlidGenerator::class)->generate();
            }
        });

        static::updating(function (Task $task) {
            if ($task->isDirty('task_uid')) {
                $task->task_uid = $task->getRawOriginal('task_uid');
            }
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class);
    }

    public function events()
    {
        return $this->hasMany(TaskEvent::class)->orderBy('sequence');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function heldBy()
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function revisionCycles()
    {
        return $this->hasMany(TaskRevisionCycle::class);
    }

    public function activeRevisionCycle()
    {
        return $this->belongsTo(TaskRevisionCycle::class, 'active_revision_cycle_id');
    }

    public function submissions()
    {
        return $this->hasMany(TaskSubmission::class);
    }

    public function latestSubmission()
    {
        return $this->hasOne(TaskSubmission::class)->latestOfMany('submitted_at');
    }

    public function approvals()
    {
        return $this->hasMany(TaskApproval::class);
    }

    public function approval()
    {
        return $this->hasOne(TaskApproval::class)->latestOfMany('approved_at');
    }
}
