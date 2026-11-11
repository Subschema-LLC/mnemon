<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $sha = hash('sha256', $this->faker->unique()->uuid());

        return [
            'room_id' => Room::factory(),
            'original_name' => $this->faker->word().'.pdf',
            'type' => 'pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'sha256' => $sha,
            'disk' => 'local',
            'path' => "documents/{$sha}.pdf",
            'status' => Document::STATUS_PROCESSED,
            'chunk_count' => 0,
            'source' => 'test',
        ];
    }
}
