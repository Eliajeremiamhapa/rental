<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\RentalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rental extends Model
{
    /** @use HasFactory<RentalFactory> */
    use HasFactory, OwnedByUser;

    /**
     * Statuses that represent a tenancy which is still running: the room is
     * still occupied and rent is still billable. "expiring_soon" is produced by
     * rental:check-expiry and must therefore be treated as live everywhere,
     * otherwise a rental silently stops generating charges and disappears from
     * dashboards right before it ends.
     */
    public const LIVE_STATUSES = ['active', 'expiring_soon'];

    protected $fillable = [
        'tenant_id',
        'room_id',
        'house_id',
        'rent_amount',
        'rent_amount_note',
        'start_date',
        'end_date',
        'period_months',
        'due_day',
        'deposit_amount',
        'deposit_status',
        'deposit_notes',
        'status',
        'terminated_reason',
        'terminated_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rent_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'terminated_at' => 'datetime',
            'period_months' => 'integer',
            'due_day' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A room can only have one running contract at a time. Whenever a new
        // rental is created, any other still-live rental for the same room
        // is superseded and expires automatically.
        static::created(function (Rental $rental) {
            if (in_array($rental->status, self::LIVE_STATUSES, true)) {
                static::where('id', '!=', $rental->id)
                    ->where('room_id', $rental->room_id)
                    ->whereIn('status', self::LIVE_STATUSES)
                    ->update(['status' => 'expired']);
            }
        });
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

    public function rentCharges(): HasMany
    {
        return $this->hasMany(RentCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasManyThrough(Payment::class, RentCharge::class);
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->whereHas('house', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id));
    }

    /**
     * Restrict a query to tenancies that are still running.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }
}
