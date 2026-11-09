<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A drawer session_digest proposed and held for the owner's review
 * (MNEMON_DIGEST_REVIEW) instead of writing it. Approving writes the drawer;
 * discarding keeps the row as a record of what was turned away.
 *
 * @property string $status
 * @property string $reason
 * @property string $wing_slug
 * @property string $room_slug
 * @property bool $propose_new_room
 * @property string $content
 * @property string $source
 * @property array<string, mixed>|null $metadata
 * @property float|null $confidence
 * @property int|null $drawer_id
 */
class DrawerProposal extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** The proposal scored below the digest's confidence floor (review mode "low" or "all"). */
    public const REASON_BELOW_FLOOR = 'below_floor';

    /** Every proposal waits for the owner (review mode "all"). */
    public const REASON_REVIEW_ALL = 'review_all';

    protected $fillable = [
        'status',
        'reason',
        'wing_slug',
        'room_slug',
        'propose_new_room',
        'content',
        'source',
        'metadata',
        'confidence',
        'session_id',
        'turn_start',
        'turn_end',
        'occurred_at',
        'drawer_id',
        'decided_by',
        'decided_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'propose_new_room' => 'boolean',
            'confidence' => 'float',
            'turn_start' => 'integer',
            'turn_end' => 'integer',
            'occurred_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function drawer(): BelongsTo
    {
        return $this->belongsTo(Drawer::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
