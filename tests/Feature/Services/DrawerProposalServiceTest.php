<?php

namespace Tests\Feature\Services;

use App\Models\Drawer;
use App\Models\DrawerProposal;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use App\Services\DrawerProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DrawerProposalServiceTest extends TestCase
{
    use RefreshDatabase;

    private DrawerProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DrawerProposalService::class);
        $work = Wing::factory()->create(['slug' => 'work', 'name' => 'Work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
    }

    public function test_approving_files_the_drawer_as_proposed_and_keeps_the_proposal(): void
    {
        $user = User::factory()->create();
        $proposal = DrawerProposal::factory()->create([
            'content' => 'Ship the queue first',
            'metadata' => ['captured_via' => 'session_digest', 'session_id' => 's1', 'rationale' => 'for the reviewer'],
        ]);

        $drawer = $this->service->approve($proposal, $user);

        $this->assertSame('Ship the queue first', $drawer->content);
        $this->assertSame('work', $drawer->room->wing->slug);
        $this->assertSame('claude-code:session_digest', $drawer->source);
        $this->assertSame('raw', $drawer->tier);
        $this->assertSame('s1', $drawer->metadata['session_id']);
        $this->assertArrayNotHasKey('rationale', $drawer->metadata);
        $this->assertSame($proposal->id, $drawer->metadata['review']['proposal_id']);
        $this->assertSame($user->id, $drawer->metadata['review']['approved_by']);
        $this->assertFalse($drawer->metadata['review']['edited']);

        $proposal->refresh();
        $this->assertSame(DrawerProposal::STATUS_APPROVED, $proposal->status);
        $this->assertSame($drawer->id, $proposal->drawer_id);
        $this->assertSame($user->id, $proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
        $this->assertSame('Ship the queue first', $proposal->content, 'The proposal keeps the text as it was proposed');
    }

    public function test_approving_with_edits_files_the_owners_version_and_marks_it_edited(): void
    {
        Wing::factory()->create(['slug' => 'decisions', 'name' => 'Decisions']);
        $proposal = DrawerProposal::factory()->create(['content' => 'Maybe ship the queue first?']);

        $drawer = $this->service->approve($proposal, null, 'Ship the review queue first.', 'decisions', 'Roadmap');

        $this->assertSame('Ship the review queue first.', $drawer->content);
        $this->assertSame(['decisions', 'roadmap'], [$drawer->room->wing->slug, $drawer->room->slug]);
        $this->assertSame('review_queue', $drawer->room->metadata['created_via']);
        $this->assertTrue($drawer->metadata['review']['edited']);
        $this->assertSame('Maybe ship the queue first?', $proposal->fresh()->content);
    }

    public function test_a_proposed_room_is_created_on_approval(): void
    {
        $proposal = DrawerProposal::factory()->create(['room_slug' => 'retros', 'propose_new_room' => true]);

        $drawer = $this->service->approve($proposal);

        $this->assertSame('retros', $drawer->room->slug);
    }

    public function test_review_never_creates_a_wing(): void
    {
        $proposal = DrawerProposal::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->approve($proposal, null, null, 'nowhere');
        } finally {
            $this->assertFalse(Wing::where('slug', 'nowhere')->exists());
            $this->assertTrue($proposal->fresh()->isPending());
            $this->assertSame(0, Drawer::count());
        }
    }

    public function test_a_proposal_is_decided_once(): void
    {
        $proposal = DrawerProposal::factory()->create();
        $this->service->approve($proposal);

        $this->expectException(InvalidArgumentException::class);
        $this->service->approve($proposal);
    }

    public function test_approving_content_the_room_already_holds_links_the_existing_drawer(): void
    {
        $existing = Drawer::create(['content' => 'Same words', 'room_id' => Room::where('slug', 'notes')->value('id'), 'source' => 'manual']);
        $proposal = DrawerProposal::factory()->create(['content' => 'Same words']);

        $drawer = $this->service->approve($proposal);

        $this->assertSame($existing->id, $drawer->id);
        $this->assertSame(1, Drawer::count());
        $this->assertSame($existing->id, $proposal->fresh()->drawer_id);
    }

    public function test_discarding_keeps_the_row_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $proposal = DrawerProposal::factory()->create();

        $this->service->reject($proposal, $user);

        $proposal->refresh();
        $this->assertSame(DrawerProposal::STATUS_REJECTED, $proposal->status);
        $this->assertSame($user->id, $proposal->decided_by);
        $this->assertSame(0, Drawer::count());
    }
}
