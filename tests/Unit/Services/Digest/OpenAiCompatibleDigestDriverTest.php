<?php

namespace Tests\Unit\Services\Digest;

use App\Models\LlmModel;
use App\Services\Digest\DigestUnavailable;
use App\Services\Digest\OpenAiCompatibleDigestDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleDigestDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mnemon.digest.compatible' => [
                'base_url' => 'https://openrouter.ai/api/v1/',
                'api_key' => 'or-key',
                'model' => 'qwen/qwen3-235b',
                'json_mode' => true,
            ],
        ]);
    }

    private function reply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120]];
    }

    public function test_posts_chat_completions_to_the_configured_service(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->reply('{"proposals":[]}'))]);

        $driver = new OpenAiCompatibleDigestDriver;
        $this->assertSame([], $driver->digest('t', []));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer or-key')
            && $r['model'] === 'qwen/qwen3-235b'
            && $r['max_tokens'] === 16000
            && ! isset($r->data()['max_completion_tokens'])
            && $r['response_format'] === ['type' => 'json_object']);
        $this->assertSame(900, $driver->lastUsage()['prompt_tokens']);
        $this->assertSame('openai-compatible', $driver->provider());
    }

    public function test_json_mode_can_be_turned_off_and_a_local_server_needs_no_key(): void
    {
        config(['mnemon.digest.compatible.json_mode' => false, 'mnemon.digest.compatible.api_key' => null, 'mnemon.digest.compatible.base_url' => 'http://localhost:11434/v1']);
        Http::fake(['localhost:11434/*' => Http::response($this->reply('{"proposals":[]}'))]);

        (new OpenAiCompatibleDigestDriver)->digest('t', []);

        Http::assertSent(fn (Request $r) => ! isset($r->data()['response_format']) && ! $r->hasHeader('Authorization'));
    }

    public function test_a_fenced_answer_or_one_after_a_reasoning_block_is_still_read(): void
    {
        $proposal = '{"proposals":[{"content":"keep this","wing_slug":"work","room_slug":"notes","confidence":0.9,"propose_new_wing":false,"propose_new_room":false}]}';

        $answers = ["```json\n{$proposal}\n```", "<think>let me see</think>\n{$proposal}", "Here you go:\n{$proposal}\nDone."];
        $replies = Http::fakeSequence('openrouter.ai/*');
        foreach ($answers as $answer) {
            $replies->push($this->reply($answer));
        }

        foreach ($answers as $answer) {
            $this->assertSame('keep this', (new OpenAiCompatibleDigestDriver)->digest('t', [])[0]['content'], $answer);
        }
    }

    public function test_the_marked_model_wins_when_it_is_this_providers(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->reply('{"proposals":[]}'))]);
        LlmModel::create(['provider' => 'openai-compatible', 'model' => 'meta-llama/llama-4', 'is_digest_model' => true]);

        (new OpenAiCompatibleDigestDriver)->digest('t', []);

        Http::assertSent(fn (Request $r) => $r['model'] === 'meta-llama/llama-4');
    }

    public function test_without_a_base_url_or_a_model_it_fails_without_retrying(): void
    {
        Http::fake();

        config(['mnemon.digest.compatible.base_url' => null]);
        $e = $this->failureOf(fn () => (new OpenAiCompatibleDigestDriver)->digest('t', []));
        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('MNEMON_DIGEST_BASE_URL', $e->getMessage());

        config(['mnemon.digest.compatible.base_url' => 'https://openrouter.ai/api/v1', 'mnemon.digest.compatible.model' => null]);
        $e = $this->failureOf(fn () => (new OpenAiCompatibleDigestDriver)->digest('t', []));
        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('MNEMON_DIGEST_MODEL', $e->getMessage());

        Http::assertNothingSent();
    }

    private function failureOf(callable $call): DigestUnavailable
    {
        try {
            $call();
        } catch (DigestUnavailable $e) {
            return $e;
        }

        $this->fail('expected DigestUnavailable, the call returned normally');
    }
}
