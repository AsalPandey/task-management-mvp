<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class);
    }

    /** @return BelongsTo<TaskRevisionCycle, $this> */
    public function revisionCycle(): BelongsTo
    {
        return $this->belongsTo(TaskRevisionCycle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }
}
