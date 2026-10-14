<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, OwnedByUser;

    protected $fillable = [
        'house_id',
        'name',
        'description',
        'rent_amount',
        'status',
        'reserved_until',
    ];

    protected function casts(): array
    {
        return [
            'rent_amount' => 'decimal:2',
            'status' => 'string',
            'reserved_until' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function currentRental(): HasOne
    {
        return $this->hasOne(Rental::class)->live();
    }

    public function rentCharges(): HasMany
    {
        return $this->hasMany(RentCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasManyThrough(Payment::class, RentCharge::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->whereHas('house', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        $tenantId = $user->tenant?->id;
        if (! $tenantId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('rentals', fn ($q) => $q->where('tenant_id', $tenantId));
    }
}
