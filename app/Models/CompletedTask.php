<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompletedTask extends Model
{
    use SoftDeletes;
    protected $table = 'completed_tasks';
    protected $fillable = [
        'title', 'description', 'project_id', 'assignee_id', 'priority', 'status', 'progress', 'start_date', 'due_date', 'comments', 'completed_at', 'reverted',
    ];
    public function project() { return $this->belongsTo(Project::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
} 