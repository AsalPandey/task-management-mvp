<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskHistory extends Model
{
    protected $table = 'task_histories';
    protected $fillable = [
        'task_id', 'user_id', 'action', 'changes', 'created_at'
    ];
    public $timestamps = false;

    public function task() { return $this->belongsTo(Task::class); }
    public function user() { return $this->belongsTo(User::class); }
} 