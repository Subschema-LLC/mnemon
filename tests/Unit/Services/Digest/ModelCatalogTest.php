<?php

namespace Tests\Unit\Services\Digest;

use App\Models\LlmModel;
use App\Services\Digest\ModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => null,
            'services.anthropic.api_key' => null,
            'mnemon.digest.compatible.base_url' => null,
            'mnemon.llm.model' => 'gpt-shared',
            'mnemon.digest.openai_model' => null,
        ]);

        // The migration enters the shipped model; these tests enter their own.
        LlmModel::query()->delete();
    }

    public function test_openai_lists_only_chat_models_newest_first_and_caches_the_list(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-old', 'created' => 100],
            ['id' => 'text-embedding-3-small', 'created' => 300],
            ['id' => 'gpt-new', 'created' => 200],
            ['id' => 'gpt-new-audio-preview', 'created' => 250],
            ['id' => 'whisper-1', 'created' => 50],
            ['id' => 'o4-mini', 'created' => 150],
        ]])]);

        $options = ModelCatalog::modelOptions('openai');
        ModelCatalog::modelOptions('openai');

        $this->assertSame(['gpt-new', 'o4-mini', 'gpt-old', 'gpt-shared'], array_keys($options));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_without_a_key_nothing_is_fetched_and_the_configured_and_entered_models_are_offered(): void
    {
        Http::fake();
        LlmModel::create(['provider' => 'openai', 'model' => 'gpt-entered']);

        $options = ModelCatalog::modelOptions('openai');

        $this->assertArrayHasKey('gpt-shared', $options);
        $this->assertArrayHasKey('gpt-entered', $options);
        Http::assertNothingSent();
    }

    public function test_anthropic_without_a_key_offers_the_known_models(): void
    {
        Http::fake();

        $options = ModelCatalog::modelOptions('anthropic');

        $this->assertSame('Claude Haiku 5.5', $options['claude-haiku-5-5']);
        $this->assertArrayHasKey('claude-opus-5-5', $options);
        Http::assertNothingSent();
    }

    public function test_anthropic_with_a_key_lists_its_models_with_their_names(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant']);
        Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [
            ['type' => 'model', 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5'],
        ]])]);

        $options = ModelCatalog::modelOptions('anthropic');

        $this->assertSame('Claude Sonnet 5.5 (claude-sonnet-5-5)', $options['claude-sonnet-5-5']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'sk-ant') && $r->hasHeader('anthropic-version'));
    }

    public function test_an_openai_compatible_service_lists_everything_it_serves(): void
    {
        config(['mnemon.digest.compatible.base_url' => 'http://localhost:11434/v1', 'mnemon.digest.compatible.api_key' => null]);
        Http::fake(['localhost:11434/v1/models' => Http::response(['data' => [['id' => 'llama3.3:70b'], ['id' => 'qwen3:32b']]])]);

        $this->assertSame(['llama3.3:70b', 'qwen3:32b'], array_keys(ModelCatalog::modelOptions('openai-compatible')));
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_a_failed_list_falls_back_and_is_not_kept_for_the_full_hour(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'down'], 503)]);

        $this->assertSame(['gpt-shared'], array_keys(ModelCatalog::modelOptions('openai')));

        $this->travel(ModelCatalog::FAILURE_TTL + 1)->seconds();
        ModelCatalog::modelOptions('openai');

        Http::assertSentCount(2);
    }

    public function test_the_current_value_is_always_an_option(): void
    {
        $this->assertSame('my-finetune (added by hand)', ModelCatalog::modelOptions('openai', 'my-finetune')['my-finetune']);
        $this->assertSame('gpt-shared', ModelCatalog::modelOptions('openai', 'gpt-shared')['gpt-shared']);
    }

    public function test_providers_offer_the_digest_providers_then_those_entered_for_comparison(): void
    {
        config(['mnemon.digest.compatible.base_url' => 'https://api.groq.com/openai/v1']);
        LlmModel::create(['provider' => 'mistral', 'model' => 'mistral-large']);

        $options = ModelCatalog::providerOptions('deepseek');

        $this->assertSame(['openai', 'anthropic', 'openai-compatible', 'mistral', 'deepseek'], array_keys($options));
        $this->assertSame('OpenAI-compatible (api.groq.com)', $options['openai-compatible']);
        $this->assertSame('mistral (price comparison only)', $options['mistral']);
    }

    public function test_the_openai_chat_filter(): void
    {
        foreach (['gpt-5.6-luna', 'gpt-4o-mini', 'o3', 'o4-mini', 'chatgpt-4o-latest'] as $id) {
            $this->assertTrue(ModelCatalog::isOpenAiChatModel($id), $id);
        }
        foreach (['text-embedding-3-large', 'gpt-4o-realtime-preview', 'gpt-4o-mini-tts', 'gpt-image-1', 'whisper-1', 'dall-e-3', 'omni-moderation-latest', 'gpt-4o-search-preview'] as $id) {
            $this->assertFalse(ModelCatalog::isOpenAiChatModel($id), $id);
        }
    }
}
