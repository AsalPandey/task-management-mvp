<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowNotificationIntent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
