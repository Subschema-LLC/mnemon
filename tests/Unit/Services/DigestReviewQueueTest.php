<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\DrawerProposal;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MNEMON_DIGEST_REVIEW: off (today's behaviour), low (below-floor proposals
 * wait for review instead of being dropped), all (every proposal waits).
 */
class DigestReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    private const HIGH = ['content' => 'Decided to ship the review queue first', 'wing_slug' => 'work', 'room_slug' => 'notes',
        'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false, 'rationale' => 'A decision'];

    private const LOW = ['content' => 'Maybe look at the retention defaults someday', 'wing_slug' => 'work', 'room_slug' => 'notes',
        'confidence' => 0.3, 'propose_new_wing' => false, 'propose_new_room' => false, 'rationale' => 'Vague aside'];

    protected function setUp(): void
    {
        parent::setUp();
        Wing::factory()->create(['slug' => 'work', 'name' => 'Work']);
    }

    public function test_off_keeps_todays_behaviour(): void
    {
        config(['mnemon.digest.review' => 'off']);

        $result = $this->digest([self::HIGH, self::LOW]);

        $this->assertCount(1, $result['persisted']);
        $this->assertSame([], $result['queued_for_review']);
        $this->assertSame(0, DrawerProposal::count());
        $this->assertSame(1, Drawer::count());
    }

    public function test_low_holds_below_floor_proposals_instead_of_dropping_them(): void
    {
        config(['mnemon.digest.review' => 'low']);

        $result = $this->digest([self::HIGH, self::LOW]);

        $this->assertCount(1, $result['persisted']);
        $this->assertSame(1, Drawer::count());
        $this->assertCount(1, $result['queued_for_review']);
        $this->assertSame(['wing' => 'work', 'room' => 'notes', 'reason' => DrawerProposal::REASON_BELOW_FLOOR],
            array_diff_key($result['queued_for_review'][0], ['id' => true]));

        $proposal = DrawerProposal::sole();
        $this->assertSame(self::LOW['content'], $proposal->content);
        $this->assertSame(0.3, $proposal->confidence);
        $this->assertSame('claude-code:session_digest', $proposal->source);
        $this->assertSame('Vague aside', $proposal->metadata['rationale']);
        $this->assertSame(['sess-r', 0, 6], [$proposal->session_id, $proposal->turn_start, $proposal->turn_end]);
        $this->assertTrue($proposal->isPending());
    }

    public function test_all_holds_every_proposal_but_new_wings_still_go_to_pending_wings(): void
    {
        config(['mnemon.digest.review' => 'all']);
        $newWing = ['content' => 'Atlas kickoff', 'wing_slug' => 'project:atlas', 'room_slug' => 'planning',
            'confidence' => 0.9, 'propose_new_wing' => true, 'propose_new_room' => true, 'rationale' => 'New project'];
        $lowNewWing = ['confidence' => 0.2, 'content' => 'Atlas maybe'] + $newWing;

        $result = $this->digest([self::HIGH, self::LOW, $newWing, $lowNewWing], projectFiling: 'model');

        $this->assertSame([], $result['persisted']);
        $this->assertSame(0, Drawer::count());
        $this->assertEqualsCanonicalizing([DrawerProposal::REASON_REVIEW_ALL, DrawerProposal::REASON_BELOW_FLOOR],
            array_column($result['queued_for_review'], 'reason'));
        $this->assertCount(1, $result['pending_wings']);
        $this->assertSame(1, WikiPendingWing::count());
    }

    public function test_a_proposed_room_is_created_only_when_the_proposal_is_approved(): void
    {
        config(['mnemon.digest.review' => 'all']);

        $this->digest([['room_slug' => 'retros', 'propose_new_room' => true] + self::HIGH]);

        $proposal = DrawerProposal::sole();
        $this->assertSame('retros', $proposal->room_slug);
        $this->assertTrue($proposal->propose_new_room);
        $this->assertFalse(Room::where('slug', 'retros')->exists());
    }

    public function test_a_room_the_wing_lacks_and_the_model_did_not_propose_falls_back_to_notes(): void
    {
        config(['mnemon.digest.review' => 'low']);

        $this->digest([['room_slug' => 'nowhere'] + self::LOW]);

        $this->assertSame(['notes', false], [DrawerProposal::sole()->room_slug, DrawerProposal::sole()->propose_new_room]);
    }

    public function test_held_content_is_redacted_like_a_drawer(): void
    {
        config(['mnemon.digest.review' => 'low']);

        $this->digest([['content' => 'Rotate the key sk-abcdefghijklmnopqrstuvwxyz123456 before Friday'] + self::LOW]);

        $content = DrawerProposal::sole()->content;
        $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz123456', $content);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $content);
    }

    public function test_a_replayed_slice_reports_what_it_held(): void
    {
        config(['mnemon.digest.review' => 'low']);
        $first = $this->digest([self::HIGH, self::LOW]);

        $replay = $this->digest([self::HIGH, self::LOW]);

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['queued_for_review'], $replay['queued_for_review']);
        $this->assertSame(1, DrawerProposal::count());
    }

    public function test_a_restricted_token_holds_nothing_outside_its_wings(): void
    {
        config(['mnemon.digest.review' => 'low']);
        Wing::factory()->create(['slug' => 'personal', 'name' => 'Personal']);

        $result = $this->digest([['wing_slug' => 'personal'] + self::LOW], allowed: ['work'], projectFiling: 'model');

        $this->assertSame([], $result['queued_for_review']);
        $this->assertSame(1, $result['dropped_outside_wings']);
        $this->assertSame(0, DrawerProposal::count());
    }

    public function test_an_unknown_mode_is_treated_as_off(): void
    {
        config(['mnemon.digest.review' => 'everything']);

        $result = $this->digest([self::LOW]);

        $this->assertSame([], $result['queued_for_review']);
        $this->assertSame(0, DrawerProposal::count());
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<string>|null  $allowed
     */
    private function digest(array $proposals, ?array $allowed = null, ?string $projectFiling = null): array
    {
        $llm = new class($proposals)
        {
            public function __construct(public array $proposals) {}

            public function digest(string $transcript, array $context): array
            {
                return $this->proposals;
            }
        };

        return (new SessionDigestService($llm, app(DrawerWriteService::class)))->run(
            sessionId: 'sess-r',
            harness: 'claude-code',
            turnRange: ['start' => 0, 'end' => 6],
            transcript: 'anything',
            recentDrawerIds: [],
            allowedWingPatterns: $allowed,
            projectFiling: $projectFiling,
        );
    }
}
