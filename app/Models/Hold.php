<?php

namespace App\Models;

use App\Enums\HoldStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    protected $fillable = [
        'slot_id',
        'idempotency_key',
        'status',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => HoldStatuses::STATUS_HELD->value
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function isActive(): bool
    {
        return $this->status === HoldStatuses::STATUS_HELD->value &&
            $this->expires_at &&
            $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === HoldStatuses::STATUS_HELD->value &&
            $this->expires_at &&
            $this->expires_at->isPast();
    }

}