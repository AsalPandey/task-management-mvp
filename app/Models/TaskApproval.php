<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskApproval extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'override_reason',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'is_override' => 'boolean',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function submission()
    {
        return $this->belongsTo(TaskSubmission::class);
    }

    public function revisionCycle()
    {
        return $this->belongsTo(TaskRevisionCycle::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function assignedReviewer()
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }
}
