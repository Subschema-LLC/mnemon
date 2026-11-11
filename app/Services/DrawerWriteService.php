<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class DrawerWriteService
{
    public function __construct(
        private readonly ContentSanitizer $sanitizer,
    ) {}

    /**
     * Create a drawer, auto-creating the wing and room if they don't exist.
     * Content is sanitized before storage to redact secrets.
     *
     * @param  string  $wingSlug  Slug for the wing (e.g. "work")
     * @param  string  $roomSlug  Slug for the room (e.g. "notes")
     * @param  string  $content  Verbatim drawer content
     * @param  string  $source  Source attribution (token name or explicit override)
     * @param  array|null  $metadata  Optional JSON metadata
     * @param  CarbonInterface|null  $occurredAt  When the conversation happened; a later time is clamped to now
     * @param  int|null  $documentId  The upload the content was extracted from
     * @param  bool  $deduplicate  False when the drawer must belong to its caller even if the
     *                             room already holds the same text: an upload owns its drawers,
     *                             so deleting one version of a file never takes another's with it
     */
    public function createDrawer(
        string $wingSlug,
        string $roomSlug,
        string $content,
        string $source,
        ?array $metadata = null,
        ?CarbonInterface $occurredAt = null,
        ?int $documentId = null,
        bool $deduplicate = true,
    ): Drawer {
        $room = $this->roomFor($wingSlug, $roomSlug);

        // Redact first, then fingerprint: two captures differing only in a
        // secret that gets removed are the same drawer once stored.
        $sanitized = $this->sanitizer->sanitize($content);

        $existing = $deduplicate
            ? Drawer::where('room_id', $room->id)->where('content_hash', hash('sha256', $sanitized))->first()
            : null;

        if ($existing !== null) {
            // Handed back rather than created. wasRecentlyCreated is false on a
            // fetched model, so callers can tell the difference without a
            // signature change — and nothing silently reports a write that did
            // not happen.
            return $existing;
        }

        return Drawer::create([
            'content' => $sanitized,
            'room_id' => $room->id,
            'document_id' => $documentId,
            'source' => $source,
            'metadata' => $metadata,
            'tier' => 'raw',
            'occurred_at' => $occurredAt !== null && $occurredAt->isFuture() ? now() : $occurredAt,
        ]);
    }

    /**
     * The room a drawer would be filed in, created with its wing when either
     * is missing -- the same way createDrawer() names them.
     */
    public function roomFor(string $wingSlug, string $roomSlug): Room
    {
        $wing = Wing::firstOrCreate(
            ['slug' => $wingSlug],
            ['name' => Str::title(str_replace('-', ' ', $wingSlug))],
        );

        return Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => $roomSlug],
            ['name' => Str::title(str_replace('-', ' ', $roomSlug)), 'wing_id' => $wing->id],
        );
    }
}
