<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Documents\DocumentActions;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Drawers\DrawerResource;
use App\Models\Document;
use App\Models\Drawer;
use App\Models\User;
use App\Services\Documents\DocumentIngestService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class DocumentResourceTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    private function ingest(string $contents = "name\nAda\n", string $name = 'team.csv', string $wing = 'work'): Document
    {
        return app(DocumentIngestService::class)->ingest($this->tempFile($contents), $name, $wing, null, 'test')->document;
    }

    public function test_the_list_shows_documents_and_the_upload_action(): void
    {
        $doc = $this->ingest();

        Livewire::test(ListDocuments::class)
            ->assertCanSeeTableRecords([$doc])
            ->assertActionExists('upload');

        $this->get(DocumentResource::getUrl('index'))->assertOk()->assertSee('team.csv');
    }

    public function test_upload_ingests_each_file_and_reports_problems(): void
    {
        Livewire::test(ListDocuments::class)
            ->callAction('upload', data: [
                'files' => [
                    UploadedFile::fake()->createWithContent('team.csv', "name\nAda\n"),
                    UploadedFile::fake()->createWithContent('notes.txt', 'nope'),
                ],
                'wing' => 'Work Stuff',
                'room' => 'people',
                'note' => 'from HR',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Uploaded with problems.');

        $doc = Document::sole();
        $this->assertSame(['team.csv', 'work-stuff', 'people', DocumentActions::SOURCE, 'from HR'],
            [$doc->original_name, $doc->room->wing->slug, $doc->room->slug, $doc->source, $doc->note]);
        $this->assertSame(1, Drawer::count());
    }

    public function test_upload_requires_files_and_a_wing(): void
    {
        Livewire::test(ListDocuments::class)
            ->callAction('upload', data: ['files' => [], 'wing' => ''])
            ->assertHasActionErrors(['files' => 'required', 'wing' => 'required']);
    }

    public function test_the_view_page_shows_the_text_and_links_each_drawer(): void
    {
        $doc = $this->ingest();
        $drawer = $doc->drawers()->sole();

        $this->get(DocumentResource::getUrl('view', ['record' => $doc]))
            ->assertOk()
            ->assertSee('Ada')
            ->assertSee(DrawerResource::getUrl('view', ['record' => $drawer]), false);

        // And the drawer links back.
        $this->get(DrawerResource::getUrl('view', ['record' => $drawer]))
            ->assertOk()
            ->assertSee(DocumentResource::getUrl('view', ['record' => $doc]), false);
    }

    public function test_a_failed_document_shows_its_error_and_can_be_retried(): void
    {
        $doc = $this->ingest("%PDF-1.4\nbroken", 'broken.pdf');

        $this->get(DocumentResource::getUrl('view', ['record' => $doc]))->assertOk()->assertSee('may be damaged');

        Livewire::test(ViewDocument::class, ['record' => $doc->getKey()])
            ->assertActionVisible('retry')
            ->callAction('retry')
            ->assertNotified('No text extracted');
    }

    public function test_download_sends_the_original_as_an_attachment(): void
    {
        $doc = $this->ingest('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><text>hi</text></svg>', 'x.svg');

        $response = DocumentActions::downloadResponse($doc);

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));

        Livewire::test(ViewDocument::class, ['record' => $doc->getKey()])
            ->callAction('download')
            ->assertFileDownloaded('x.svg');
    }

    public function test_delete_removes_the_document_its_drawers_and_file(): void
    {
        $doc = $this->ingest();

        Livewire::test(ListDocuments::class)->callAction(TestAction::make('delete')->table($doc));

        $this->assertNull(Document::find($doc->id));
        $this->assertSame(0, Drawer::count());
        Storage::disk('local')->assertMissing($doc->path);
    }

    public function test_bulk_delete(): void
    {
        $a = $this->ingest("a\n1\n", 'a.csv');
        $b = $this->ingest("b\n2\n", 'b.csv');

        Livewire::test(ListDocuments::class)->callTableBulkAction('delete', [$a, $b]);

        $this->assertSame(0, Document::count());
    }

    public function test_livewire_never_previews_an_uploaded_svg(): void
    {
        $this->assertNotContains('svg', config('livewire.temporary_file_upload.preview_mimes'));
        $this->assertContains('max:'.(25 * 1024), config('livewire.temporary_file_upload.rules'));
    }
}
