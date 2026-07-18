<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskEvent extends Model
{
    protected $guarded = [
        'id',
        'event_uid',
        'task_id',
        'sequence',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'changed_fields' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
