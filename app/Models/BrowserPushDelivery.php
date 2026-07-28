<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserPushDelivery extends Model
{
    protected $guarded = ['id'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BrowserPushSubscription::class, 'browser_push_subscription_id');
    }
}
