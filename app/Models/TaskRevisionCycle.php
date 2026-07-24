<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRevisionCycle extends Model
{
    protected $hidden = [
        'reopen_reason',
    ];

    protected $fillable = [
        'task_id',
        'cycle_number',
        'requested_by',
        'requested_at',
        'formal_feedback',
        'revision_due_date',
        'started_at',
        'resubmitted_at',
        'resolved_at',
        'origin',
        'reopen_reason',
    ];

    protected $casts = [
        'cycle_number' => 'integer',
        'requested_at' => 'datetime',
        'revision_due_date' => 'date',
        'started_at' => 'datetime',
        'resubmitted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function submissions()
    {
        return $this->hasMany(TaskSubmission::class, 'revision_cycle_id');
    }
}
