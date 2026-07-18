<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompletedTask extends Model
{
    use SoftDeletes;

    protected $table = 'completed_tasks';

    protected $fillable = [
        'original_task_id',
        'project_id',
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
        'completed_at',
        'completed_by',
        'reverted',
        'reverted_by',
        'reverted_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'reverted' => 'boolean',
        'reverted_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
