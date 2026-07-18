<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'user_id',
        'action',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
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
