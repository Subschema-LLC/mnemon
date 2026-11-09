<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\Embeddings\VoyageDriver;
use App\Services\PalaceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A provider that embeds documents and queries differently gets each form in
 * its place: drawers are stored with the document embedding, searches use the
 * query embedding, and the two still meet in the same index.
 */
class QueryEmbeddingSearchTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (env('CI_REQUIRE_PGSQL')) {
            $this->fail('the pgsql matrix leg is not on PostgreSQL — the DB_CONNECTION override is broken');
        }

        $this->markTestSkipped('semantic search needs PostgreSQL with pgvector');
    }

    public function test_drawers_are_stored_as_documents_and_found_by_a_query_embedding(): void
    {
        $this->requirePostgres();

        config([
            'mnemon.embedding.driver' => 'voyage',
            'mnemon.embedding.drivers.voyage' => ['model' => 'voyage-4', 'dimensions' => 4],
            'services.voyage.api_key' => 'voyage-key',
        ]);

        // Different vectors for each form, so the test can tell which was used.
        Http::fake([VoyageDriver::ENDPOINT => function (Request $request) {
            return Http::response(['data' => [['embedding' => $request['input_type'] === 'query' ? [0.9, 0.1, 0.0, 0.0] : [1.0, 0.0, 0.0, 0.0]]]]);
        }]);

        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes']);
        $drawer = Drawer::create(['room_id' => $room->id, 'content' => 'retries use exponential backoff', 'source' => 'test']);

        $stored = DB::selectOne('SELECT embedding::text AS e FROM drawers WHERE id = ?', [$drawer->id]);
        $this->assertSame('[1,0,0,0]', $stored->e);

        $results = app(PalaceSearchService::class)->search('how do we back off?', mode: 'semantic');

        $this->assertSame([$drawer->id], $results->pluck('id')->all());
        Http::assertSent(fn (Request $r) => $r['input'] === 'how do we back off?' && $r['input_type'] === 'query');
        Http::assertSentCount(2);
    }
}
