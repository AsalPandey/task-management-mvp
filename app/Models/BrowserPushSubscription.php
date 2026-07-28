<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrowserPushSubscription extends Model
{
    protected $guarded = ['id', 'user_id', 'endpoint_hash'];

    protected $hidden = [
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_secret',
    ];

    protected function casts(): array
    {
        return [
            'last_successful_delivery_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'disabled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(BrowserPushDelivery::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereNull('disabled_at')->whereNull('revoked_at');
    }

    public static function endpointHash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
