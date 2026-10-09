<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, OwnedByUser;

    protected $fillable = [
        'user_id',
        'owner_id',
        'full_name',
        'phone',
        'id_number',
        'email',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function rentCharges(): HasMany
    {
        return $this->hasMany(RentCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function currentRoom(): ?Room
    {
        $rental = $this->rentals()
            ->live()
            ->first();

        return $rental?->room;
    }

    public function getCurrentRentalAttribute(): ?Rental
    {
        return $this->rentals()
            ->live()
            ->with(['room.house', 'rentCharges'])
            ->first();
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            // Direct ownership: the owner created this tenant.
            $q->where('owner_id', $user->id);
            // Backwards-compatible: tenants linked to this owner's houses via a rental.
            $q->orWhereHas('rentals', function ($rq) use ($user) {
                $rq->whereHas('house', fn ($h) => $h->where('user_id', $user->id));
            });
        });
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
