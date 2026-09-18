<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskNotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'attempt_count' => 'integer',
        'claimed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
