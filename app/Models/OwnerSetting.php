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
     * Settings are optional: a brand-new owner with no row still gets the
     * template defaults rather than a broken letter.
     */
    public static function forOwner(User|int $owner): self
    {
        $userId = $owner instanceof User ? $owner->id : $owner;

        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'company_name' => config('services.receipt.company_name', 'UVIMO'),
                'currency' => config('app.currency', 'TZS'),
                'payment_accounts' => [],
            ],
        );
    }
}
