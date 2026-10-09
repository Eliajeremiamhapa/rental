<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\RentChargeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentCharge extends Model
{
    /** @use HasFactory<RentChargeFactory> */
    use HasFactory, OwnedByUser;

    protected $fillable = [
        'rental_id',
        'tenant_id',
        'room_id',
        'period_start',
        'period_end',
        'due_date',
        'expected_amount',
        'paid_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'expected_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    protected function balance(): Attribute
    {
        return Attribute::get(function () {
            return (float) $this->expected_amount - (float) $this->paid_amount;
        });
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->whereHas('room.house', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id));
    }
}
