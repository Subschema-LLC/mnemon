<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded file and the drawers its text became.
 *
 * The drawers are the searchable content; this row is their provenance. It
 * keeps the original (privately, on `disk` at `path`) so it can be
 * downloaded or extracted again, and so deleting an upload removes every
 * drawer it made.
 *
 * @property int|null $room_id
 * @property string $original_name
 * @property string $type
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property string $status
 * @property string|null $error
 * @property int $chunk_count
 * @property string|null $note
 * @property string $source
 * @property array<string, mixed>|null $metadata
 */
class Document extends Model
{
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    /** Text was extracted and filed as drawers. */
    public const STATUS_PROCESSED = 'processed';

    /** The file holds no text to extract: a scanned PDF, an image with vision off. */
    public const STATUS_NO_TEXT = 'no_text';

    /** Extraction failed; the original is kept so it can be tried again. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'room_id',
        'original_name',
        'type',
        'mime',
        'size',
        'sha256',
        'disk',
        'path',
        'status',
        'error',
        'chunk_count',
        'note',
        'source',
        'metadata',
        'occurred_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PROCESSING,
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'chunk_count' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function drawers(): HasMany
    {
        return $this->hasMany(Drawer::class)->orderBy('id');
    }

    public function wingSlug(): ?string
    {
        return $this->room?->wing?->slug;
    }
}
