<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectHistory extends Model
{
    protected $table = 'project_histories';
    protected $fillable = [
        'project_id', 'user_id', 'action', 'changes', 'created_at'
    ];
    public $timestamps = false;

    public function project() { return $this->belongsTo(Project::class); }
    public function user() { return $this->belongsTo(User::class); }
} 