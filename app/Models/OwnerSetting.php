<?php

namespace App\Models;

use Database\Factories\OwnerSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerSetting extends Model
{
    /** @use HasFactory<OwnerSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'contact_phone',
        'contact_email',
        'address',
        'logo_path',
        'signature_path',
        'currency',
        'payment_accounts',
        'lipa_namba',
        'lipa_namba_name',
    ];

    protected function casts(): array
    {
        return ['payment_accounts' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Settings are optional: a brand-new owner with no row still gets sensible
     * defaults rather than a broken letter. The company name defaults to the
     * owner's own account name so their documents are never stamped with the
     * platform brand by accident.
     */
    public static function forOwner(User|int $owner): self
    {
        $user = $owner instanceof User ? $owner : User::find($owner);
        $userId = $user?->id ?? ($owner instanceof User ? $owner->id : $owner);

        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'company_name' => $user?->name
                    ?: config('services.receipt.company_name', 'Nipangishe'),
                'currency' => config('app.currency', 'TZS'),
                'payment_accounts' => [],
            ],
        );
    }
}
