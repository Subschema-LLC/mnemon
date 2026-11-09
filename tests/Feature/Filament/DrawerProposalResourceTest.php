<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DrawerProposals\DrawerProposalResource;
use App\Filament\Resources\DrawerProposals\Pages\ListDrawerProposals;
use App\Models\Drawer;
use App\Models\DrawerProposal;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DrawerProposalResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $work = Wing::factory()->create(['slug' => 'work', 'name' => 'Work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
    }

    public function test_the_queue_lists_pending_proposals_by_default(): void
    {
        $pending = DrawerProposal::factory()->create();
        $decided = DrawerProposal::factory()->create(['status' => DrawerProposal::STATUS_REJECTED]);

        Livewire::test(ListDrawerProposals::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$decided]);
        $this->assertSame('1', DrawerProposalResource::getNavigationBadge());
    }

    public function test_approve_files_the_drawer(): void
    {
        $proposal = DrawerProposal::factory()->create(['content' => 'Approved as is']);

        Livewire::test(ListDrawerProposals::class)->callTableAction('approve', $proposal);

        $this->assertSame(DrawerProposal::STATUS_APPROVED, $proposal->fresh()->status);
        $this->assertSame('Approved as is', Drawer::sole()->content);
    }

    public function test_edit_and_approve_files_the_owners_version(): void
    {
        Wing::factory()->create(['slug' => 'decisions', 'name' => 'Decisions']);
        $proposal = DrawerProposal::factory()->create(['content' => 'rough']);

        Livewire::test(ListDrawerProposals::class)
            ->callTableAction('edit', $proposal, data: ['content' => 'Polished', 'wing_slug' => 'decisions', 'room_slug' => 'roadmap'])
            ->assertHasNoTableActionErrors();

        $drawer = Drawer::sole();
        $this->assertSame('Polished', $drawer->content);
        $this->assertSame(['decisions', 'roadmap'], [$drawer->room->wing->slug, $drawer->room->slug]);
        $this->assertSame('rough', $proposal->fresh()->content);
    }

    public function test_discard_writes_nothing(): void
    {
        $proposal = DrawerProposal::factory()->create();

        Livewire::test(ListDrawerProposals::class)->callTableAction('discard', $proposal);

        $this->assertSame(DrawerProposal::STATUS_REJECTED, $proposal->fresh()->status);
        $this->assertSame(0, Drawer::count());
    }

    public function test_bulk_approve_files_each_pending_proposal(): void
    {
        $proposals = DrawerProposal::factory()->count(3)->sequence(
            ['content' => 'one'], ['content' => 'two'], ['content' => 'three'],
        )->create();

        Livewire::test(ListDrawerProposals::class)->callTableBulkAction('approve_selected', $proposals);

        $this->assertSame(3, Drawer::count());
        $this->assertSame(0, DrawerProposal::where('status', DrawerProposal::STATUS_PENDING)->count());
        $this->assertNull(DrawerProposalResource::getNavigationBadge());
    }
}
