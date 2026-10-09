<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory, OwnedByUser;

    public $timestamps = false;

    protected $fillable = [
        'receipt_number',
        'payment_id',
        'tenant_id',
        'room_id',
        'house_id',
        'amount_paid',
        'payment_date',
        'payment_method',
        'rent_period',
        'balance_after',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payment_date' => 'date',
            'issued_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->whereHas('house', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id));
    }
}
