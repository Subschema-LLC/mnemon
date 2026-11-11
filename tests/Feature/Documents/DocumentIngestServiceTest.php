<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\Digest\DigestUnavailable;
use App\Services\Documents\DocumentIngestService;
use App\Services\Documents\DocumentRejected;
use App\Services\Documents\ImageCaptioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class DocumentIngestServiceTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private DocumentIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none', 'mnemon.documents.disk' => 'local']);
        Storage::fake('local');
        $this->service = app(DocumentIngestService::class);
    }

    private function fakeCaptioner(string|\Throwable $answer = 'A red square on white.'): object
    {
        $fake = new class($answer) implements ImageCaptioner
        {
            public int $calls = 0;

            public function __construct(private string|\Throwable $answer) {}

            public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
            {
                $this->calls++;
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return ['text' => $this->answer, 'model' => 'gpt-vision-test', 'provider' => 'openai'];
            }
        };
        $this->app->instance(ImageCaptioner::class, $fake);
        $this->service = app(DocumentIngestService::class);

        return $fake;
    }

    public function test_a_document_is_stored_and_its_text_filed_as_drawers(): void
    {
        $path = $this->docx([['h1' => 'Plan'], 'Ship uploads by Friday.'], title: 'Plan');

        $result = $this->service->ingest($path, 'C:\\Users\\scott\\plan.docx', 'work', null, 'test-device', note: 'from the offsite');

        $doc = $result->document;
        $this->assertTrue($result->created);
        $this->assertSame(Document::STATUS_PROCESSED, $doc->status);
        $this->assertSame('plan.docx', $doc->original_name, 'a client path is reduced to its name');
        $this->assertSame(['work', 'documents'], [$doc->room->wing->slug, $doc->room->slug]);
        $this->assertSame(hash_file('sha256', $path), $doc->sha256);
        Storage::disk('local')->assertExists("documents/{$doc->sha256}.docx");
        $this->assertSame('Plan', $doc->metadata['title']);

        $drawer = Drawer::sole();
        $this->assertSame($doc->id, $drawer->document_id);
        $this->assertSame("Document: plan.docx\nNote: from the offsite\n\n# Plan\n\nShip uploads by Friday.", $drawer->content);
        $this->assertSame('test-device', $drawer->source);
        $this->assertSame(['captured_via' => 'document_upload', 'document_id' => $doc->id, 'document_name' => 'plan.docx',
            'document_type' => 'docx', 'part' => 1, 'parts' => 1, 'section' => 'Plan'], $drawer->metadata);
        $this->assertSame(1, $doc->chunk_count);
    }

    public function test_a_long_document_becomes_several_labelled_drawers(): void
    {
        config(['mnemon.documents.chunk_chars' => 300]);
        $pages = array_map(fn ($i) => "Page {$i} ".str_repeat('lorem ', 40), range(1, 3));

        $doc = $this->service->ingest($this->pdf($pages), 'deck.pdf', 'work', 'reports', 'test')->document;

        $contents = Drawer::orderBy('id')->pluck('content')->all();
        $this->assertCount(3, $contents);
        $this->assertStringStartsWith('Document: deck.pdf · page 1 (1 of 3)', $contents[0]);
        $this->assertStringStartsWith('Document: deck.pdf · page 3 (3 of 3)', $contents[2]);
        $this->assertSame(3, $doc->chunk_count);
        $this->assertSame('reports', $doc->room->slug);
    }

    public function test_the_same_file_in_the_same_room_is_not_stored_twice(): void
    {
        $path = $this->tempFile("a,b\n1,2\n");

        $first = $this->service->ingest($path, 'x.csv', 'work', null, 'test');
        $second = $this->service->ingest($path, 'renamed.csv', 'work', null, 'test');

        $this->assertFalse($second->created);
        $this->assertSame($first->document->id, $second->document->id);
        $this->assertSame(1, Document::count());
        $this->assertSame(1, Drawer::count());

        // Another room is another upload, sharing the stored file.
        $third = $this->service->ingest($path, 'x.csv', 'work', 'elsewhere', 'test');
        $this->assertTrue($third->created);
        $this->assertSame($first->document->path, $third->document->path);
    }

    public function test_two_versions_of_a_file_each_keep_their_own_drawers(): void
    {
        $v1 = $this->service->ingest($this->tempFile("name\nalpha\n"), 'list.csv', 'work', null, 'test')->document;
        $v2 = $this->service->ingest($this->tempFile("name\nalpha\n\n"), 'list.csv', 'work', null, 'test')->document;

        $this->assertNotSame($v1->sha256, $v2->sha256);
        $this->assertSame(1, $v1->drawers()->count());
        $this->assertSame(1, $v2->drawers()->count(), 'identical text is not handed back from the other version');

        $this->service->delete($v1);
        $this->assertSame(1, $v2->drawers()->count());
    }

    public function test_an_image_is_described_and_the_drawer_says_the_text_is_generated(): void
    {
        $captioner = $this->fakeCaptioner();

        $doc = $this->service->ingest($this->image('png'), 'logo.png', 'work', null, 'test')->document;

        $this->assertSame(1, $captioner->calls);
        $this->assertSame(Document::STATUS_PROCESSED, $doc->status);
        $drawer = Drawer::sole();
        $this->assertSame("Image: logo.png\nDescription written by gpt-vision-test, not text from the file.\n\nA red square on white.", $drawer->content);
        $this->assertSame('gpt-vision-test', $drawer->metadata['generated_by']);
    }

    public function test_a_failed_description_keeps_the_file_and_a_reupload_retries_it(): void
    {
        $captioner = $this->fakeCaptioner(new DigestUnavailable('the vision call failed with HTTP 503', retryable: true));
        $path = $this->image('jpg');

        $doc = $this->service->ingest($path, 'photo.jpg', 'work', null, 'test')->document;

        $this->assertSame(Document::STATUS_FAILED, $doc->status);
        $this->assertSame('The image could not be described: the vision call failed with HTTP 503. Retry the document.', $doc->error);
        $this->assertSame(0, Drawer::count());
        Storage::disk('local')->assertExists($doc->path);

        $this->fakeCaptioner('A photo.');
        $again = $this->service->ingest($path, 'photo.jpg', 'work', null, 'test');

        $this->assertFalse($again->created);
        $this->assertSame(Document::STATUS_PROCESSED, $again->document->status);
        $this->assertNull($again->document->error);
        $this->assertSame(1, Drawer::count());
    }

    public function test_retrying_replaces_the_drawers_an_earlier_attempt_made(): void
    {
        $this->fakeCaptioner('first description');
        $doc = $this->service->ingest($this->image('png'), 'a.png', 'work', null, 'test')->document;

        $this->fakeCaptioner('second description');
        $this->service->process($doc);

        $this->assertSame(['second description'], Drawer::pluck('content')->map(fn ($c) => substr($c, strrpos($c, "\n") + 1))->all());
        $this->assertSame(1, Drawer::onlyTrashed()->count());
    }

    public function test_a_file_with_no_text_is_kept_and_a_note_becomes_its_drawer(): void
    {
        $doc = $this->service->ingest($this->pdf(['']), 'scan.pdf', 'work', null, 'test', note: 'signed lease, page 1')->document;

        $this->assertSame(Document::STATUS_NO_TEXT, $doc->status);
        $this->assertStringContainsString('Scanned PDFs are not read yet', $doc->error);
        $this->assertSame("Document: scan.pdf\n\nNote: signed lease, page 1", Drawer::sole()->content);

        $bare = $this->service->ingest($this->pdf(['', '']), 'blank.pdf', 'work', null, 'test')->document;
        $this->assertSame(Document::STATUS_NO_TEXT, $bare->status);
        $this->assertSame(0, $bare->chunk_count);
    }

    public function test_vision_off_stores_an_image_without_text(): void
    {
        config(['mnemon.documents.vision' => false]);
        $captioner = $this->fakeCaptioner();

        $doc = $this->service->ingest($this->image('webp'), 'pic.webp', 'work', null, 'test')->document;

        $this->assertSame(0, $captioner->calls);
        $this->assertSame(Document::STATUS_NO_TEXT, $doc->status);
    }

    public function test_too_many_chunks_fail_without_writing_drawers(): void
    {
        config(['mnemon.documents.max_chunks' => 2, 'mnemon.documents.chunk_chars' => 200]);

        $doc = $this->service->ingest($this->pdf(array_map(fn ($i) => str_repeat("p{$i} ", 80), range(1, 4))), 'big.pdf', 'work', null, 'test')->document;

        $this->assertSame(Document::STATUS_FAILED, $doc->status);
        $this->assertStringContainsString('more than the 2 allowed', $doc->error);
        $this->assertSame(0, Drawer::count());
    }

    public function test_a_damaged_file_fails_with_a_reason_and_keeps_the_original(): void
    {
        $doc = $this->service->ingest($this->tempFile("%PDF-1.4\ngarbage"), 'broken.pdf', 'work', null, 'test')->document;

        $this->assertSame(Document::STATUS_FAILED, $doc->status);
        $this->assertStringContainsString('may be damaged', $doc->error);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_refusals_store_nothing(): void
    {
        $cases = [
            [$this->image('png'), 'logo.pdf', 'its content is not a PDF'],
            [$this->tempFile('x'), 'notes.txt', 'does not read .txt'],
            [$this->tempFile(''), 'empty.csv', 'is empty'],
        ];

        foreach ($cases as [$path, $name, $message]) {
            try {
                $this->service->ingest($path, $name, 'work', null, 'test');
                $this->fail("{$name} should be refused");
            } catch (DocumentRejected $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        config(['mnemon.documents.max_mb' => 1]);
        $this->expectExceptionMessage('larger than 1 MB');
        try {
            $this->service->ingest($this->tempFile(str_repeat('a,b'.PHP_EOL, 400000)), 'huge.csv', 'work', null, 'test');
        } finally {
            $this->assertSame(0, Document::count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_the_wing_and_room_are_slugged(): void
    {
        $doc = $this->service->ingest($this->tempFile("a\n1\n"), 'a.csv', 'My_Wing%', 'Board Packs', 'test')->document;

        $this->assertSame(['my-wing', 'board-packs'], [$doc->room->wing->slug, $doc->room->slug]);

        $this->expectException(DocumentRejected::class);
        $this->service->ingest($this->tempFile("a\n1\n"), 'a.csv', '%%%', null, 'test');
    }

    public function test_a_future_time_is_clamped_to_now(): void
    {
        Carbon::setTestNow('2026-11-11 12:00:00');

        $doc = $this->service->ingest($this->tempFile("a\n1\n"), 'a.csv', 'work', null, 'test', occurredAt: Carbon::parse('2030-01-01'))->document;

        $this->assertTrue($doc->occurred_at->equalTo(now()));
        $this->assertTrue(Drawer::sole()->occurred_at->equalTo(now()));
    }

    public function test_delete_removes_drawers_row_and_file_but_keeps_a_shared_file(): void
    {
        $path = $this->tempFile("a\n1\n");
        $one = $this->service->ingest($path, 'a.csv', 'work', null, 'test')->document;
        $two = $this->service->ingest($path, 'a.csv', 'work', 'other', 'test')->document;

        $this->service->delete($one);

        $this->assertNull(Document::find($one->id));
        $this->assertSame(1, Drawer::onlyTrashed()->count());
        Storage::disk('local')->assertExists($two->path);

        $this->service->delete($two);
        Storage::disk('local')->assertMissing($two->path);
    }

    public function test_a_document_left_processing_past_the_time_limit_is_processed_again(): void
    {
        $room = Room::create(['wing_id' => Wing::create(['name' => 'Work', 'slug' => 'work'])->id, 'name' => 'Documents', 'slug' => 'documents']);
        $path = $this->tempFile("a\n1\n");
        $sha = hash_file('sha256', $path);
        Storage::disk('local')->put("documents/{$sha}.csv", file_get_contents($path));
        $stuck = Document::factory()->create([
            'room_id' => $room->id, 'sha256' => $sha, 'type' => 'csv', 'path' => "documents/{$sha}.csv",
            'status' => Document::STATUS_PROCESSING,
        ]);

        $this->assertFalse($this->service->ingest($path, 'a.csv', 'work', null, 'test')->document->status === Document::STATUS_PROCESSED,
            'a recent one is left to the request still working on it');

        $this->travel(DocumentIngestService::timeLimit() + 120)->seconds();
        $result = $this->service->ingest($path, 'a.csv', 'work', null, 'test');

        $this->assertSame($stuck->id, $result->document->id);
        $this->assertSame(Document::STATUS_PROCESSED, $result->document->status);
    }
}
