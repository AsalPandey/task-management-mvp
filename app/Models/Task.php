<?php

namespace App\Models;

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
        'deadline_reminder_sent_at' => 'datetime',
        'overdue_notification_sent_at' => 'datetime',
    ];

    public const STATUSES = ['Not Started', 'In Progress', 'Completed', 'On Hold'];

    public const PRIORITIES = ['Low', 'Medium', 'High'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
