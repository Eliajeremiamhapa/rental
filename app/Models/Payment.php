<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, OwnedByUser;

    protected $fillable = [
        'rent_charge_id',
        'tenant_id',
        'recorded_by',
        'amount',
        'payment_date',
        'payment_method',
        'reference_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function rentCharge(): BelongsTo
    {
        return $this->belongsTo(RentCharge::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->whereHas('rentCharge.room.house', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id));
    }
}
