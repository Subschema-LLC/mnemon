<?php

namespace Tests\Unit;

use App\Contracts\EmbedsQueries;
use App\Services\EmbeddingManager;
use App\Services\Embeddings\GeminiDriver;
use App\Services\Embeddings\OpenAiCompatibleDriver;
use App\Services\Embeddings\VoyageDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class EmbeddingProvidersTest extends TestCase
{
    // ── OpenAI-compatible ──────────────────────────────────────────────

    public function test_openai_compatible_posts_to_the_configured_base_url_with_the_key(): void
    {
        Http::fake(['api.mistral.ai/v1/embeddings' => Http::response(['data' => [['embedding' => array_fill(0, 1024, 0.25)]]])]);

        $vector = (new OpenAiCompatibleDriver('https://api.mistral.ai/v1/', 'mistral-key', 'mistral-embed', 1024))->embed('hello');

        $this->assertCount(1024, $vector);
        $this->assertSame(0.25, $vector[0]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mistral.ai/v1/embeddings'
            && $r->hasHeader('Authorization', 'Bearer mistral-key')
            && $r['model'] === 'mistral-embed'
            && $r['input'] === 'hello');
    }

    public function test_openai_compatible_sends_no_authorization_header_without_a_key(): void
    {
        Http::fake(['localhost:1234/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

        $vector = (new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'local-model', 3))->embed('hello');

        $this->assertSame([0.1, 0.2, 0.3], $vector);
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_openai_compatible_is_not_a_query_embedder(): void
    {
        $this->assertNotInstanceOf(EmbedsQueries::class, new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'm', 3));
    }

    public function test_a_vector_of_the_wrong_width_is_refused_with_the_setting_to_change(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['data' => [['embedding' => array_fill(0, 768, 0.1)]]])]);

        $vector = (new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'm', 1024))->embed('hello');

        $this->assertNull($vector);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, '768-dimension')
            && str_contains($message, 'MNEMON_EMBEDDING_DIMENSIONS to 768'));
    }

    public function test_a_failed_call_returns_null_and_logs(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

        $this->assertNull((new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'm', 3))->embed('hello'));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'embedding failed') && $context['status'] === 429);
    }

    public function test_a_response_without_a_vector_returns_null(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->assertNull((new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'm', 3))->embed('hello'));
    }

    public function test_a_connection_error_returns_null(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->assertNull((new OpenAiCompatibleDriver('http://localhost:1234/v1', null, 'm', 3))->embed('hello'));
    }

    // ── Voyage ─────────────────────────────────────────────────────────

    public function test_voyage_embeds_stored_text_as_a_document_and_searches_as_a_query(): void
    {
        Http::fake([VoyageDriver::ENDPOINT => Http::response(['data' => [['embedding' => array_fill(0, 1024, 0.1)]]])]);
        $driver = new VoyageDriver('voyage-key', 'voyage-4', 1024);

        $this->assertCount(1024, $driver->embed('stored'));
        $this->assertCount(1024, $driver->embedQuery('searched'));

        Http::assertSent(fn (Request $r) => $r['input'] === 'stored' && $r['input_type'] === 'document'
            && $r['model'] === 'voyage-4' && $r->hasHeader('Authorization', 'Bearer voyage-key'));
        Http::assertSent(fn (Request $r) => $r['input'] === 'searched' && $r['input_type'] === 'query');
    }

    public function test_voyage_asks_for_a_width_only_when_it_is_not_the_default(): void
    {
        Http::fake([
            VoyageDriver::ENDPOINT => Http::sequence()
                ->push(['data' => [['embedding' => array_fill(0, 1024, 0.1)]]])
                ->push(['data' => [['embedding' => array_fill(0, 512, 0.1)]]]),
        ]);

        (new VoyageDriver('k', 'voyage-3-large', 1024))->embed('a');
        (new VoyageDriver('k', 'voyage-4-lite', 512))->embed('b');

        Http::assertSent(fn (Request $r) => $r['input'] === 'a' && ! isset($r->data()['output_dimension']));
        Http::assertSent(fn (Request $r) => $r['input'] === 'b' && $r['output_dimension'] === 512);
    }

    public function test_voyage_without_a_key_makes_no_call(): void
    {
        Http::fake();

        $this->assertNull((new VoyageDriver(null, 'voyage-4', 1024))->embed('a'));
        $this->assertNull((new VoyageDriver('', 'voyage-4', 1024))->embedQuery('a'));
        Http::assertNothingSent();
    }

    // ── Gemini ─────────────────────────────────────────────────────────

    public function test_gemini_sends_the_task_type_width_and_key_header(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => [3.0, 4.0]]])]);
        $driver = new GeminiDriver('gemini-key', 'gemini-embedding-001', 2);

        $driver->embed('stored');
        $driver->embedQuery('searched');

        Http::assertSent(fn (Request $r) => $r->url() === GeminiDriver::BASE_URL.'gemini-embedding-001:embedContent'
            && $r->hasHeader('x-goog-api-key', 'gemini-key')
            && ! $r->hasHeader('Authorization')
            && $r['model'] === 'models/gemini-embedding-001'
            && $r['content'] === ['parts' => [['text' => 'stored']]]
            && $r['taskType'] === 'RETRIEVAL_DOCUMENT'
            && $r['outputDimensionality'] === 2);
        Http::assertSent(fn (Request $r) => $r['content']['parts'][0]['text'] === 'searched' && $r['taskType'] === 'RETRIEVAL_QUERY');
    }

    public function test_gemini_embedding_2_gets_the_task_as_a_text_prefix_and_no_task_type(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => [0.6, 0.8]]])]);
        $driver = new GeminiDriver('gemini-key', 'gemini-embedding-2', 2);

        $driver->embed('stored');
        $driver->embedQuery('searched');

        Http::assertSent(fn (Request $r) => $r->url() === GeminiDriver::BASE_URL.'gemini-embedding-2:embedContent'
            && $r['content']['parts'][0]['text'] === 'title: none | text: stored'
            && ! isset($r->data()['taskType'])
            && $r['outputDimensionality'] === 2);
        Http::assertSent(fn (Request $r) => $r['content']['parts'][0]['text'] === 'task: search result | query: searched'
            && ! isset($r->data()['taskType']));
    }

    public function test_gemini_vectors_are_normalised_to_unit_length(): void
    {
        Http::fake(['*' => Http::response(['embedding' => ['values' => [3.0, 4.0]]])]);

        $vector = (new GeminiDriver('k', 'gemini-embedding-001', 2))->embed('a');

        $this->assertEqualsWithDelta([0.6, 0.8], $vector, 1e-9);
    }

    public function test_gemini_without_a_key_makes_no_call(): void
    {
        Http::fake();

        $this->assertNull((new GeminiDriver(null, 'gemini-embedding-001', 768))->embed('a'));
        Http::assertNothingSent();
    }

    public function test_gemini_refuses_a_model_name_that_would_change_the_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeminiDriver('k', '../files?x=1', 768);
    }

    // ── Manager ────────────────────────────────────────────────────────

    public function test_manager_resolves_openai_compatible_from_config(): void
    {
        config([
            'mnemon.embedding.driver' => 'openai-compatible',
            'mnemon.embedding.drivers.openai-compatible' => [
                'base_url' => 'https://api.together.xyz/v1', 'api_key' => 'k', 'model' => 'BAAI/bge-large-en-v1.5', 'dimensions' => 1024,
            ],
        ]);

        $driver = (new EmbeddingManager)->driver();

        $this->assertInstanceOf(OpenAiCompatibleDriver::class, $driver);
        $this->assertSame(1024, $driver->dimensions());
    }

    public function test_manager_names_the_missing_openai_compatible_setting(): void
    {
        foreach (['base_url' => 'MNEMON_EMBEDDING_BASE_URL', 'model' => 'MNEMON_EMBEDDING_MODEL', 'dimensions' => 'MNEMON_EMBEDDING_DIMENSIONS'] as $key => $env) {
            $config = ['base_url' => 'https://api.together.xyz/v1', 'api_key' => null, 'model' => 'm', 'dimensions' => 1024];
            $config[$key] = null;
            config(['mnemon.embedding.drivers.openai-compatible' => $config]);

            try {
                (new EmbeddingManager)->driver('openai-compatible');
                $this->fail("no error for a missing {$env}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($env, $e->getMessage());
            }
        }
    }

    public function test_manager_refuses_a_base_url_that_is_not_http(): void
    {
        config(['mnemon.embedding.drivers.openai-compatible' => ['base_url' => 'file:///etc', 'api_key' => null, 'model' => 'm', 'dimensions' => 3]]);

        $this->expectException(InvalidArgumentException::class);
        (new EmbeddingManager)->driver('openai-compatible');
    }

    public function test_manager_resolves_voyage_and_gemini_with_their_keys(): void
    {
        config([
            'services.voyage.api_key' => 'voyage-key',
            'services.gemini.api_key' => 'gemini-key',
            'mnemon.embedding.drivers.voyage' => ['model' => 'voyage-4', 'dimensions' => 1024],
            'mnemon.embedding.drivers.gemini' => ['model' => 'gemini-embedding-001', 'dimensions' => 1536],
        ]);
        Http::fake([
            VoyageDriver::ENDPOINT => Http::response(['data' => [['embedding' => array_fill(0, 1024, 0.1)]]]),
            'generativelanguage.googleapis.com/*' => Http::response(['embedding' => ['values' => array_fill(0, 1536, 0.1)]]),
        ]);

        $voyage = (new EmbeddingManager)->driver('voyage');
        $gemini = (new EmbeddingManager)->driver('gemini');

        $this->assertInstanceOf(VoyageDriver::class, $voyage);
        $this->assertInstanceOf(GeminiDriver::class, $gemini);
        $this->assertSame(1536, $gemini->dimensions());
        $this->assertNotNull($voyage->embed('a'));
        $this->assertNotNull($gemini->embed('a'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer voyage-key'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'gemini-key'));
    }

    public function test_shipped_defaults_for_the_new_drivers(): void
    {
        // Read from the config file itself, so a test's config() override
        // elsewhere cannot hide a changed default.
        $shipped = require base_path('config/mnemon.php');
        $drivers = $shipped['embedding']['drivers'];

        $this->assertSame(['voyage-4', 1024], [$drivers['voyage']['model'], $drivers['voyage']['dimensions']]);
        $this->assertSame(['gemini-embedding-2', 768], [$drivers['gemini']['model'], $drivers['gemini']['dimensions']]);
    }
}
