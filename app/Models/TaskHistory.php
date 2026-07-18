<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskHistory extends Model
{
    protected $table = 'task_histories';

    protected $fillable = [
        'task_id',
        'completed_task_id',
        'project_id',
        'original_task_id',
        'task_title',
        'user_id',
        'action',
        'changes',
        'created_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function completedTask()
    {
        return $this->belongsTo(CompletedTask::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
