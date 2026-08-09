<?php

namespace App\Models;

use App\Enums\SubscriptionPeriodicity;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'client_id',
    'name',
    'description',
    'periodicity',
    'amount',
    'currency_code',
    'starts_on',
    'ends_on',
    'status',
    'billing_day',
    'next_generation_on',
    'reminder_days_before',
    'remind_on',
    'last_reminder_at',
    'terms',
    'notes',
    'user_id',
])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'periodicity' => SubscriptionPeriodicity::class,
            'status' => SubscriptionStatus::class,
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_generation_on' => 'date',
            'remind_on' => 'date',
            'last_reminder_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
