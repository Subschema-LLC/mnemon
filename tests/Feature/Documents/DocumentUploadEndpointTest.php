<?php

namespace Tests\Feature\Documents;

use App\Models\BrainSession;
use App\Models\Document;
use App\Models\Drawer;
use App\Models\McpClientRestriction;
use App\Models\McpTokenRestriction;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use RuntimeException;
use Tests\TestCase;

class DocumentUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);
        Storage::fake('local');

        $clients = app(ClientRepository::class);
        try {
            $clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient('Test Client', 'users');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>|null  $patterns
     */
    private function upload(array $fields, ?array $patterns = null, array $scopes = ['mcp:use'], ?UploadedFile $file = null): TestResponse
    {
        $token = User::factory()->create()->createToken('Laptop', $scopes);
        if ($patterns !== null) {
            McpTokenRestriction::create(['access_token_id' => $token->token->id, 'wing_patterns' => $patterns]);
        }
        $this->app['auth']->forgetGuards();

        return $this->post('/api/documents', $fields + ['file' => $file ?? UploadedFile::fake()->createWithContent('team.csv', "name,role\nAda,eng\n")], [
            'Authorization' => 'Bearer '.$token->accessToken,
            'Accept' => 'application/json',
        ]);
    }

    public function test_an_upload_is_stored_extracted_and_audited(): void
    {
        $res = $this->upload(['wing' => 'work', 'room' => 'people', 'note' => 'the team list']);

        $res->assertCreated()
            ->assertJson(['name' => 'team.csv', 'type' => 'csv', 'status' => 'processed', 'wing' => 'work', 'room' => 'people', 'chunks' => 1, 'created' => true]);
        $drawer = Drawer::sole();
        $this->assertSame([$drawer->id], $res->json('drawer_ids'));
        $this->assertSame('Laptop', $drawer->source);
        $this->assertStringContainsString('Ada,eng', $drawer->content);

        $audit = BrainSession::sole();
        $this->assertSame(['document_upload', 1], [$audit->tool_name, $audit->result_count]);
        $this->assertSame('team.csv', $audit->input['file_name']);
    }

    public function test_the_same_file_again_answers_200_with_the_existing_document(): void
    {
        $this->upload(['wing' => 'work'])->assertCreated();
        $res = $this->upload(['wing' => 'work']);

        $res->assertOk()->assertJson(['created' => false]);
        $this->assertSame(1, Document::count());
    }

    public function test_a_project_files_into_its_wing(): void
    {
        $this->upload(['project' => 'Atlas'])->assertCreated()->assertJson(['wing' => 'project-atlas']);
    }

    public function test_a_failed_extraction_is_reported_with_the_document_kept(): void
    {
        $res = $this->upload(['wing' => 'work'], file: UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\ngarbage"));

        $res->assertCreated()->assertJson(['status' => 'failed']);
        $this->assertStringContainsString('may be damaged', $res->json('error'));
        $this->assertSame(1, Document::count());
    }

    public function test_a_refused_file_answers_422_and_stores_nothing(): void
    {
        $res = $this->upload(['wing' => 'work'], file: UploadedFile::fake()->createWithContent('notes.txt', 'hello'));

        $res->assertStatus(422)->assertJson(['error' => 'Mnemon does not read .txt files; it reads docx, pptx, pdf, csv, png, jpg, webp, svg.']);
        $this->assertSame(0, Document::count());
        $this->assertSame('failed', BrainSession::sole()->outcome);
    }

    public function test_validation_errors(): void
    {
        $this->upload([])->assertStatus(422)->assertJsonValidationErrors(['wing', 'project']);
        $this->upload(['wing' => 'a', 'project' => 'b'])->assertStatus(422);

        config(['mnemon.documents.max_mb' => 1]);
        $this->upload(['wing' => 'work'], file: UploadedFile::fake()->create('big.pdf', 2048))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'larger than 1 MB']);
    }

    public function test_without_a_token_the_answer_is_401(): void
    {
        $this->post('/api/documents', ['wing' => 'work'], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_a_token_without_the_scope_is_refused(): void
    {
        $this->upload(['wing' => 'work'], scopes: [])->assertForbidden();
        $this->assertSame(0, Document::count());
    }

    public function test_a_restricted_token_writes_only_to_its_wings(): void
    {
        $this->upload(['wing' => 'work'], ['work'])->assertCreated();

        Wing::create(['name' => 'Personal', 'slug' => 'personal']);
        $hidden = $this->upload(['wing' => 'personal'], ['work']);
        $missing = $this->upload(['wing' => 'personal-ghost'], ['work']);

        $hidden->assertForbidden();
        $missing->assertForbidden();
        $this->assertSame(
            str_replace('personal', '<ARG>', $hidden->getContent()),
            str_replace('personal-ghost', '<ARG>', $missing->getContent()),
            'a hidden wing must look exactly like a missing one',
        );
        $this->assertSame(1, Document::count());
        $this->assertFalse(Wing::where('slug', 'personal-ghost')->exists());
        $this->assertSame(2, BrainSession::where('outcome', 'denied')->count());
    }

    public function test_a_restricted_token_never_creates_a_project_wing(): void
    {
        $this->upload(['project' => 'secret'], ['work', 'project-*'])->assertForbidden();
        $this->assertFalse(Wing::where('slug', 'project-secret')->exists());

        Wing::create(['name' => 'project:atlas', 'slug' => 'project-atlas']);
        $this->upload(['project' => 'atlas'], ['project-atlas'])->assertCreated()->assertJson(['wing' => 'project-atlas']);
    }

    public function test_client_restrictions_apply_to_uploads(): void
    {
        $token = User::factory()->create()->createToken('Laptop', ['mcp:use']);
        McpClientRestriction::create(['client_id' => (string) $token->token->client_id, 'wing_patterns' => ['work']]);

        $res = $this->post('/api/documents', [
            'wing' => 'personal',
            'file' => UploadedFile::fake()->createWithContent('a.csv', "a\n1\n"),
        ], ['Authorization' => 'Bearer '.$token->accessToken, 'Accept' => 'application/json']);

        $res->assertForbidden();
    }
}
