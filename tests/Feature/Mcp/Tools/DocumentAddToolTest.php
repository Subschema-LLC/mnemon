<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Document;
use App\Models\Drawer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DocumentAddToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);
        Storage::fake('local');
    }

    public function test_a_base64_file_is_stored_and_its_drawers_reported(): void
    {
        $res = $this->mcpCall('document_add', [
            'file_name' => 'team.csv',
            'content_base64' => chunk_split(base64_encode("name,role\nAda,eng\n"), 8),
            'wing' => 'work',
            'note' => 'the team',
            'occurred_at' => '2026-03-01T10:00:00Z',
        ]);

        $res->assertOk();
        $this->assertFalse($res->json('result.isError') ?? false, $res->getContent());
        $out = $res->json('result.structuredContent');
        $this->assertSame(['team.csv', 'processed', 'work', 'documents', 1, true], [$out['name'], $out['status'], $out['wing'], $out['room'], $out['chunks'], $out['created']]);

        $drawer = Drawer::sole();
        $this->assertSame('Test Client', $drawer->source);
        $this->assertSame('2026-03-01', $drawer->occurred_at->toDateString());

        $audit = BrainSession::sole();
        $this->assertSame('document_add', $audit->tool_name);
        $this->assertArrayNotHasKey('content_base64', $audit->input, 'the file itself is never written to the audit trail');
    }

    public function test_a_project_files_into_its_wing(): void
    {
        $res = $this->mcpCall('document_add', ['file_name' => 'a.csv', 'content_base64' => base64_encode("a\n1\n"), 'project' => 'Atlas']);

        $this->assertSame('project-atlas', $res->json('result.structuredContent.wing'));
    }

    public function test_bad_base64_and_refused_types_are_errors_that_store_nothing(): void
    {
        $bad = $this->mcpCall('document_add', ['file_name' => 'a.csv', 'content_base64' => '***not base64***', 'wing' => 'work']);
        $this->assertTrue($bad->json('result.isError'));
        $this->assertStringContainsString('not valid base64', $bad->getContent());

        $txt = $this->mcpCall('document_add', ['file_name' => 'a.txt', 'content_base64' => base64_encode('hi'), 'wing' => 'work']);
        $this->assertTrue($txt->json('result.isError'));
        $this->assertStringContainsString('does not read .txt', $txt->getContent());

        $this->assertSame(0, Document::count());
        $this->assertSame(2, BrainSession::where('outcome', 'failed')->count());
    }

    public function test_a_file_over_the_mcp_limit_is_refused(): void
    {
        config(['mnemon.documents.mcp_max_mb' => 1]);

        $res = $this->mcpCall('document_add', [
            'file_name' => 'big.csv',
            'content_base64' => base64_encode(str_repeat("a,b\n", 300000)),
            'wing' => 'work',
        ]);

        $this->assertTrue($res->json('result.isError'));
        $this->assertStringContainsString('larger than 1 MB', $res->getContent());
        $this->assertSame(0, Document::count());
    }

    public function test_a_failed_extraction_is_reported_not_raised(): void
    {
        $res = $this->mcpCall('document_add', ['file_name' => 'x.pdf', 'content_base64' => base64_encode("%PDF-1.4\nbroken"), 'wing' => 'work']);

        $this->assertFalse($res->json('result.isError') ?? false);
        $this->assertSame('failed', $res->json('result.structuredContent.status'));
        $this->assertStringContainsString('may be damaged', $res->json('result.structuredContent.error'));
    }

    public function test_the_tool_points_chat_agents_at_drawer_add(): void
    {
        $res = $this->mcpRequest('tools/list', []);

        $tool = collect($res->json('result.tools'))->firstWhere('name', 'document_add');
        $this->assertStringContainsString('use drawer_add', $tool['description']);
        $this->assertSame(['file_name', 'content_base64'], $tool['inputSchema']['required']);
    }
}
