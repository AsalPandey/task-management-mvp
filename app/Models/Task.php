<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

/**
 * TODO: Implement TaskPolicy for authorization.
 * Register in AuthServiceProvider and use in controllers for update, delete, and bulk actions.
 */
class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'assignee_id', 'priority', 'status', 'progress', 'start_date', 'due_date', 'comments',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class);
    }
} 