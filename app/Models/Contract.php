<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, OwnedByUser;

    public const STATUSES = ['draft', 'pending_signature', 'viewed', 'signed', 'declined', 'void'];

    /**
     * Statuses in which the tenant may still be asked to sign. Once a contract
     * leaves these it is treated as final and is never re-opened.
     */
    public const AWAITING_SIGNATURE = ['pending_signature', 'viewed'];

    protected $fillable = [
        'contract_number',
        'rental_id',
        'tenant_id',
        'house_id',
        'room_id',
        'owner_id',
        'status',
        'template_version',
        'language',
        'description',
        'clause_overrides',
        'clause_text',
        'extra_clauses',
        'content_snapshot',
        'rent_amount',
        'total_amount',
        'daily_rate',
        'period_months',
        'notice_months',
        'notice_per_day',
        'service_fee',
        'due_day',
        'payment_commitment_months',
        'short_commitment_months',
        'currency',
        'start_date',
        'end_date',
        'deposit_amount',
        'signature_token',
        'token_expires_at',
        'sent_at',
        'viewed_at',
        'view_count',
        'signer_name',
        'signer_phone',
        'signer_ip',
        'signer_user_agent',
        'signer_consented_at',
        'signer_consent_text',
        'signer_consent_version',
        'signature_image',
        'document_sha256',
        'signed_at',
        'owner_signed_at',
        'owner_signature_image',
        'declined_reason',
        'declined_at',
        'voided_reason',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'clause_overrides' => 'array',
            'clause_text' => 'array',
            'extra_clauses' => 'array',
            'content_snapshot' => 'array',
            'rent_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'notice_per_day' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'period_months' => 'integer',
            'notice_months' => 'integer',
            'due_day' => 'integer',
            'payment_commitment_months' => 'integer',
            'view_count' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'token_expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signer_consented_at' => 'datetime',
            'signed_at' => 'datetime',
            'owner_signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A signed contract is evidence of an agreement. Nothing may change it
        // afterwards, so the model refuses to persist edits to a final record
        // rather than relying on every call site to remember the rule.
        static::updating(function (self $contract) {
            if (! $contract->getOriginal('status')) {
                return;
            }

            $wasFinal = in_array($contract->getOriginal('status'), ['signed', 'declined', 'void'], true);
            if (! $wasFinal) {
                return;
            }

            $protected = [
                'content_snapshot', 'document_sha256', 'signed_at', 'signer_name',
                'signer_phone', 'signer_ip', 'signer_user_agent', 'signature_image',
                'signer_consent_text', 'signer_consent_version',
                'clause_text', 'clause_overrides', 'description', 'rent_amount',
                'total_amount', 'daily_rate', 'start_date', 'end_date', 'period_months',
            ];

            $changed = array_keys($contract->getDirty());
            $attempted = array_intersect($changed, $protected);

            if ($attempted) {
                throw new \LogicException(
                    'A '.strtoupper((string) $contract->getOriginal('status'))
                    .' contract is immutable. Attempted to change: '.implode(', ', $attempted)
                );
            }
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

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class);
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['signed', 'declined', 'void'], true);
    }

    public function isAwaitingSignature(): bool
    {
        return in_array($this->status, self::AWAITING_SIGNATURE, true);
    }

    public function hasValidToken(): bool
    {
        return $this->signature_token
            && $this->isAwaitingSignature()
            && (! $this->token_expires_at || $this->token_expires_at->isFuture());
    }

    public function scopeOwnedByOwner(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }

    public function scopeOwnedByTenant(Builder $query, User $user): Builder
    {
        return $query->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id));
    }

    public function scopeSigned(Builder $query): Builder
    {
        return $query->where('status', 'signed');
    }

    public function generateSignatureToken(): string
    {
        return Str::random(64);
    }

    public function generateContractNumber(): string
    {
        $year = now()->year;

        // Match both the legacy "UV-{year}-..." numbers and the current
        // "{year}-..." format so removing the prefix never restarts the
        // sequence for a landlord who already has contracts this year.
        $last = static::query()
            ->where(function (Builder $query) use ($year): void {
                $query->where('contract_number', 'like', "UV-{$year}-%")
                    ->orWhere('contract_number', 'like', "{$year}-%");
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('contract_number');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return sprintf('%d-%04d', $year, $seq);
    }
}
