<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompletedTask extends Model
{
    use SoftDeletes;
    
    protected $table = 'completed_tasks';
    
    protected $fillable = [
        'title', 'description', 'assignee_id', 'priority', 'status', 'progress', 'start_date', 'due_date', 'comments', 'completed_at', 'reverted',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'reverted' => 'boolean',
    ];

    public function assignee() 
    { 
        return $this->belongsTo(User::class, 'assignee_id'); 
    }
} 