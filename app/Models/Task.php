<?php

namespace App\Models;

use App\Enums\TaskState;
use App\Support\TaskStateCompatibility;
use App\Support\UlidGenerator;
use App\ValueObjects\TaskDeadlineGeneration;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Canonical fields added by driver-aware migrations that schema inference cannot read.
 *
 * @property string|null $task_uid Null only on unsaved or pre-backfill retained models.
 * @property int $lock_version
 * @property int|null $assignee_id
 * @property int|null $reviewer_id
 * @property int|null $active_revision_cycle_id
 * @property array<string, mixed>|null $legacy_completion_provenance
 * @property-read string $status Human-readable label from the status accessor.
 * @property-write string|TaskState $status Canonical or legacy input normalized by the mutator.
 */
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
        'lock_version' => 'integer',
        'legacy_completion_provenance' => 'array',
    ];

    protected $attributes = [
        'lock_version' => 1,
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

    public function activeDeadlineKind(): ?string
    {
        $state = $this->machineState();
        if ($state->isFinal()) {
            return null;
        }

        if (in_array($state, [TaskState::Submitted, TaskState::InReview], true)) {
            return 'review';
        }
        if ($state === TaskState::RevisionRequested
            || (in_array($state, [TaskState::InProgress, TaskState::OnHold], true) && $this->active_revision_cycle_id && $this->revision_due_date)) {
            return 'revision';
        }

        return 'execution';
    }

    public function activeDeadlineGeneration(): ?TaskDeadlineGeneration
    {
        $kind = $this->activeDeadlineKind();
        $deadline = match ($kind) {
            'review' => $this->review_due_date,
            'revision' => $this->revision_due_date,
            'execution' => $this->execution_due_date ?? $this->due_date,
            default => null,
        };
        if (! $deadline) {
            return null;
        }
        $responsibleUserId = $kind === 'review' ? $this->reviewer_id : $this->assignee_id;
        $workflowCycleId = $kind === 'execution' ? null : $this->active_revision_cycle_id;

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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return HasMany<TaskHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    /** @return HasMany<TaskEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderBy('sequence');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<TaskRevisionCycle, $this> */
    public function revisionCycles(): HasMany
    {
        return $this->hasMany(TaskRevisionCycle::class);
    }

    /** @return BelongsTo<TaskRevisionCycle, $this> */
    public function activeRevisionCycle(): BelongsTo
    {
        return $this->belongsTo(TaskRevisionCycle::class, 'active_revision_cycle_id');
    }

    /** @return HasMany<TaskSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    /** @return HasOne<TaskSubmission, $this> */
    public function latestSubmission(): HasOne
    {
        return $this->hasOne(TaskSubmission::class)->latestOfMany('submitted_at');
    }

    /** @return HasMany<TaskApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(TaskApproval::class);
    }

    /** @return HasOne<TaskApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(TaskApproval::class)->latestOfMany('approved_at');
    }
}
