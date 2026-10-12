<?php

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A single image or video attached to a house or a room (polymorphic owner).
 *
 * Images always live on the public disk (path). Videos are either an uploaded
 * file (path) or an external embed link such as YouTube/Vimeo (url), so a small
 * listing never forces an owner to upload a heavy file.
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'type',
        'path',
        'url',
        'sort_order',
    ];

    /**
     * The storage path and morph internals are implementation details; the
     * client always renders the resolved public URL.
     */
    protected $hidden = [
        'path',
        'mediable_type',
        'mediable_id',
    ];

    protected $appends = [
        'display_url',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function getDisplayUrlAttribute(): string
    {
        return $this->publicUrl();
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    /**
     * Resolve the display URL: the external embed link when present, otherwise
     * the uploaded file through the public disk.
     */
    public function publicUrl(): string
    {
        if ($this->url) {
            return $this->url;
        }

        return $this->path ? Storage::disk('public')->url($this->path) : '';
    }
}
