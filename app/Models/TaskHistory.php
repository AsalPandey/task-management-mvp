<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistory extends Model
{
    protected $table = 'task_histories';

    protected $fillable = [
        'task_id',
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

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
