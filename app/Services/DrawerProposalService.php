<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\DrawerProposal;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The digest review queue (MNEMON_DIGEST_REVIEW).
 *
 * session_digest holds proposals here instead of writing them as drawers;
 * the owner approves (as proposed, or after editing the text, wing or room)
 * or discards each one in the admin panel. The palace itself is unchanged:
 * an approved proposal becomes an ordinary drawer through DrawerWriteService,
 * with the same redaction and deduplication as any other write.
 */
class DrawerProposalService
{
    /** off: nothing waits (the default). low: proposals below the floor wait instead of being dropped. all: every proposal waits. */
    public const MODES = ['off', 'low', 'all'];

    public function __construct(
        private readonly ContentSanitizer $sanitizer,
        private readonly DrawerWriteService $drawers,
    ) {}

    /** The configured review mode; anything unrecognised is treated as off, so a typo never holds back memory. */
    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('mnemon.digest.review', 'off')));

        if (! in_array($mode, self::MODES, true)) {
            Log::warning('mnemon: unknown MNEMON_DIGEST_REVIEW value; treating it as off', ['value' => $mode]);

            return 'off';
        }

        return $mode;
    }

    /**
     * Hold a proposal for review. Its content is redacted now, as a drawer's
     * would be: the queue must not keep a secret the palace would not.
     *
     * @param  array{reason: string, wing_slug: string, room_slug: string, propose_new_room?: bool, content: string, source: string, metadata?: array<string, mixed>|null, confidence?: float|null, session_id?: string|null, turn_start?: int|null, turn_end?: int|null, occurred_at?: mixed}  $attributes
     */
    public function queue(array $attributes): DrawerProposal
    {
        $attributes['content'] = $this->sanitizer->sanitize((string) $attributes['content']);

        return DrawerProposal::create($attributes + ['status' => DrawerProposal::STATUS_PENDING]);
    }

    /**
     * Approve a proposal: write its drawer, with the owner's edits if any.
     * The wing must already exist (review never creates wings; that stays
     * with Pending Wings). The room is created if the proposal asked for it
     * or the owner named a new one.
     */
    public function approve(
        DrawerProposal $proposal,
        ?User $by = null,
        ?string $content = null,
        ?string $wingSlug = null,
        ?string $roomSlug = null,
    ): Drawer {
        return DB::transaction(function () use ($proposal, $by, $content, $wingSlug, $roomSlug) {
            // Re-read under a lock: two reviewers (or a double click) must not write two drawers.
            $proposal = DrawerProposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            if (! $proposal->isPending()) {
                throw new InvalidArgumentException('This proposal was already '.($proposal->status === DrawerProposal::STATUS_APPROVED ? 'approved' : 'discarded').'.');
            }

            $wingSlug = Wing::slugify($wingSlug ?? $proposal->wing_slug);
            $wing = Wing::where('slug', $wingSlug)->first();
            if ($wing === null) {
                throw new InvalidArgumentException("There is no wing {$wingSlug}. Choose an existing wing.");
            }

            $roomSlug = Wing::slugify($roomSlug ?? $proposal->room_slug);
            $roomSlug = $roomSlug !== '' ? $roomSlug : 'notes';
            if (! Room::where('wing_id', $wing->id)->where('slug', $roomSlug)->exists()) {
                // Created with the flag the digest uses for rooms it makes, so they stay easy to find.
                Room::create([
                    'wing_id' => $wing->id,
                    'slug' => $roomSlug,
                    'name' => Str::headline($roomSlug),
                    'metadata' => ['auto_created' => true, 'created_via' => 'review_queue'],
                ]);
            }

            $text = trim($content ?? $proposal->content);
            if ($text === '') {
                throw new InvalidArgumentException('A drawer needs some content.');
            }
            $edited = $text !== trim($proposal->content) || $wing->slug !== $proposal->wing_slug || $roomSlug !== $proposal->room_slug;

            $drawer = $this->drawers->createDrawer(
                wingSlug: $wing->slug,
                roomSlug: $roomSlug,
                content: $text,
                source: $proposal->source,
                // The model's rationale was for the reviewer; the drawer keeps what a digest drawer keeps.
                metadata: array_merge(array_diff_key($proposal->metadata ?? [], ['rationale' => true]), [
                    'review' => array_filter([
                        'proposal_id' => $proposal->id,
                        'reason' => $proposal->reason,
                        'approved_at' => now()->toIso8601String(),
                        'approved_by' => $by?->id,
                        'edited' => $edited,
                    ], fn ($v) => $v !== null),
                ]),
                occurredAt: $proposal->occurred_at,
            );

            $proposal->update([
                'status' => DrawerProposal::STATUS_APPROVED,
                'drawer_id' => $drawer->id,
                'decided_by' => $by?->id,
                'decided_at' => now(),
            ]);

            return $drawer;
        });
    }

    /** Discard a proposal. The row stays, so what was turned away remains on record. */
    public function reject(DrawerProposal $proposal, ?User $by = null): void
    {
        DB::transaction(function () use ($proposal, $by) {
            $proposal = DrawerProposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            if (! $proposal->isPending()) {
                return;
            }
            $proposal->update([
                'status' => DrawerProposal::STATUS_REJECTED,
                'decided_by' => $by?->id,
                'decided_at' => now(),
            ]);
        });
    }
}
