<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserPushDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'attempt_count' => 'integer',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /** @return BelongsTo<BrowserPushSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BrowserPushSubscription::class, 'browser_push_subscription_id');
    }
}
