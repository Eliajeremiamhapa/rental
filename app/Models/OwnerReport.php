<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\OwnerReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A tenant's complaint against the owner of a property they have a standing
 * relationship with. Reports always start "submitted" and are worked by an
 * administrator; the reported owner is never told a report exists.
 */
class OwnerReport extends Model
{
    /** @use HasFactory<OwnerReportFactory> */
    use HasFactory, OwnedByUser;

    public const CATEGORY_SCAM = 'scam';

    public const CATEGORY_HARASSMENT = 'harassment';

    public const CATEGORY_MISREPRESENTATION = 'misrepresentation';

    public const CATEGORY_DEPOSIT_DISPUTE = 'deposit_dispute';

    public const CATEGORY_MAINTENANCE_NEGLECT = 'maintenance_neglect';

    public const CATEGORY_UNFAIR_TERMS = 'unfair_terms';

    public const CATEGORY_OTHER = 'other';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_SCAM,
        self::CATEGORY_HARASSMENT,
        self::CATEGORY_MISREPRESENTATION,
        self::CATEGORY_DEPOSIT_DISPUTE,
        self::CATEGORY_MAINTENANCE_NEGLECT,
        self::CATEGORY_UNFAIR_TERMS,
        self::CATEGORY_OTHER,
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_IN_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    protected $fillable = [
        'reporter_id',
        'owner_id',
        'house_id',
        'rental_id',
        'category',
        'subject',
        'description',
        'status',
        'admin_note',
        'handled_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Screenshots and photos the tenant attached as evidence.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->where('reporter_id', $user->id);
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }
}
