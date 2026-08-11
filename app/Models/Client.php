<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'code',
    'name',
    'party_type',
    'business_name',
    'cuit',
    'dni',
    'phone',
    'email',
    'address',
    'tax_condition',
    'status',
    'notes',
    'import_batch_id',
    'external_id',
])]
class Client extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return [
            'party_type' => \App\Enums\PartyType::class,
        ];
    }

    public function taxConditionLabel(): string
    {
        $raw = $this->tax_condition;
        if ($raw instanceof \App\Enums\TaxCondition) {
            return $raw->label();
        }

        return \App\Enums\TaxCondition::tryFrom((string) $raw)?->label() ?? (string) ($raw ?? '');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(ClientLedgerEntry::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function codeFormatted(): string
    {
        return $this->code ? sprintf('%04d', (int) $this->code) : '—';
    }

    public function labelWithCode(): string
    {
        if (! $this->code) {
            return $this->name;
        }

        return $this->codeFormatted().' — '.$this->name;
    }

    public function commercialCharges(): HasMany
    {
        return $this->hasMany(CommercialCharge::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
