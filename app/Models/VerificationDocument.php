<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner-uploaded identity or ownership document awaiting admin review. The
 * file itself lives on the private disk; only its metadata is stored here.
 */
class VerificationDocument extends Model
{
    public const TYPE_NATIONAL_ID = 'national_id';

    public const TYPE_OWNERSHIP_PROOF = 'ownership_proof';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_NATIONAL_ID,
        self::TYPE_OWNERSHIP_PROOF,
    ];

    protected $fillable = [
        'user_id',
        'type',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
