<?php

namespace App\Models;

use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * General product feedback or a satisfaction rating submitted by an owner or a
 * tenant. Unlike an OwnerReport it targets the platform, not a person, and
 * never triggers moderation — it feeds service improvement.
 */
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    public const CATEGORY_BUG = 'bug';

    public const CATEGORY_FEATURE_REQUEST = 'feature_request';

    public const CATEGORY_SUGGESTION = 'suggestion';

    public const CATEGORY_COMPLIMENT = 'compliment';

    public const CATEGORY_COMPLAINT = 'complaint';

    public const CATEGORY_OTHER = 'other';

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_FEATURE_REQUEST,
        self::CATEGORY_SUGGESTION,
        self::CATEGORY_COMPLIMENT,
        self::CATEGORY_COMPLAINT,
        self::CATEGORY_OTHER,
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_REVIEWED,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'feedback';

    protected $fillable = [
        'user_id',
        'role',
        'category',
        'rating',
        'message',
        'status',
        'admin_note',
        'handled_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
