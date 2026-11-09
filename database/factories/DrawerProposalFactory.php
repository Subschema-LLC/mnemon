<?php

namespace Database\Factories;

use App\Models\DrawerProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawerProposal>
 */
class DrawerProposalFactory extends Factory
{
    protected $model = DrawerProposal::class;

    public function definition(): array
    {
        return [
            'status' => DrawerProposal::STATUS_PENDING,
            'reason' => DrawerProposal::REASON_BELOW_FLOOR,
            'wing_slug' => 'work',
            'room_slug' => 'notes',
            'propose_new_room' => false,
            'content' => $this->faker->paragraph(),
            'source' => 'claude-code:session_digest',
            'metadata' => ['captured_via' => 'session_digest', 'harness' => 'claude-code', 'session_id' => 'sess-factory'],
            'confidence' => 0.3,
            'session_id' => 'sess-factory',
            'turn_start' => 0,
            'turn_end' => 4,
        ];
    }
}
