<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function revisionCycle()
    {
        return $this->belongsTo(TaskRevisionCycle::class, 'revision_cycle_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
