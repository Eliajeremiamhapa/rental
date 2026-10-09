<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\HouseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class House extends Model
{
    /** @use HasFactory<HouseFactory> */
    use HasFactory, OwnedByUser;

    protected $fillable = [
        'user_id',
        'name',
        'address',
        'description',
        'contact_info',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function totalRooms(): int
    {
        return $this->rooms()->count();
    }

    public function occupiedRooms(): int
    {
        return $this->rooms()
            ->where('status', 'occupied')
            ->count();
    }

    public function availableRooms(): int
    {
        return $this->rooms()
            ->where('status', 'available')
            ->count();
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}
