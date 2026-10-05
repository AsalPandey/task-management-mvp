<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSubmission extends Model
{
    protected $fillable = [
        'task_id',
        'revision_cycle_id',
        'submitted_by',
        'submitted_at',
        'submission_note',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskRevisionCycle, $this> */
    public function revisionCycle(): BelongsTo
    {
        return $this->belongsTo(TaskRevisionCycle::class, 'revision_cycle_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
