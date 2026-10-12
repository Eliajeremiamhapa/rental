<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\RentalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-initiated request to rent a specific room. Requests start
 * "pending"; an owner accepts (creating a status-pending rental that only
 * becomes billable once the tenant signs the contract) or declines.
 */
class RentalRequest extends Model
{
    /** @use HasFactory<RentalRequestFactory> */
    use HasFactory, OwnedByUser;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'room_id',
        'house_id',
        'owner_id',
        'message',
        'desired_start_date',
        'period_months',
        'status',
        'response_message',
        'response_at',
    ];

    protected function casts(): array
    {
        return [
            'desired_start_date' => 'date',
            'response_at' => 'datetime',
            'period_months' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereHas('room.house', fn ($h) => $h->where('user_id', $user->id));
        });
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->where('tenant_id', $user->tenant?->id ?? 0);
    }
}
