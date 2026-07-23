<?php

namespace App\Models;

use App\Support\UlidGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

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

    public const STATUSES = ['Not Started', 'In Progress', 'Completed', 'On Hold'];

    public const PRIORITIES = ['Low', 'Medium', 'High'];

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
}
