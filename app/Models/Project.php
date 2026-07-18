<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'project_manager_id',
        'start_date',
        'end_date',
        'status',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps()
            ->withPivot('added_by');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function histories()
    {
        return $this->hasMany(ProjectHistory::class);
    }
}
