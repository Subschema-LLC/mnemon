<?php

namespace Tests\Unit\Services\Digest;

use App\Models\LlmModel;
use App\Services\Digest\AnthropicDigestDriver;
use App\Services\Digest\DigestUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicDigestDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'sk-ant-test', 'mnemon.digest.anthropic_model' => null]);
    }

    /** @param array<int, array<string, mixed>> $proposals */
    private function reply(array $proposals, array $usage = ['input_tokens' => 1200, 'output_tokens' => 300], string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => json_encode(['proposals' => $proposals])]],
            'stop_reason' => $stop,
            'usage' => $usage,
        ];
    }

    public function test_sends_a_structured_output_request_to_the_messages_api(): void
    {
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response($this->reply([]))]);

        (new AnthropicDigestDriver)->digest('the transcript', ['existing_wings' => ['work'], 'session_wing' => 'project-mnemon']);

        Http::assertSent(function (Request $r) {
            return $r->url() === AnthropicDigestDriver::ENDPOINT
                && $r->hasHeader('x-api-key', 'sk-ant-test')
                && $r->hasHeader('anthropic-version', '2023-06-01')
                && ! $r->hasHeader('Authorization')
                && $r['model'] === 'claude-haiku-5-5'
                && $r['max_tokens'] === 16000
                && str_contains($r['system'], 'project-mnemon')
                && $r['messages'] === [['role' => 'user', 'content' => "Transcript:\n\nthe transcript"]]
                && $r['output_config']['format']['type'] === 'json_schema'
                && $r['output_config']['format']['schema'] === AnthropicDigestDriver::schema();
        });
    }

    public function test_the_schema_holds_every_object_closed_with_every_property_required(): void
    {
        // Structured outputs need additionalProperties: false on each object.
        $schema = AnthropicDigestDriver::schema();
        $item = $schema['properties']['proposals']['items'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame(array_keys($item['properties']), $item['required']);
    }

    public function test_parses_proposals_and_maps_usage_with_cache_reads_as_cached_input(): void
    {
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response($this->reply([[
            'content' => 'Retries use exponential backoff with jitter',
            'wing_slug' => 'work', 'room_slug' => null, 'confidence' => 0.8,
            'propose_new_wing' => false, 'propose_new_room' => false, 'rationale' => 'a decision',
        ]], ['input_tokens' => 1000, 'cache_read_input_tokens' => 400, 'cache_creation_input_tokens' => 100, 'output_tokens' => 250]))]);

        $driver = new AnthropicDigestDriver;
        $result = $driver->digest('t', []);

        $this->assertCount(1, $result);
        $this->assertSame('Retries use exponential backoff with jitter', $result[0]['content']);
        $this->assertNull($result[0]['room_slug']);
        $this->assertSame([
            'prompt_tokens' => 1500,
            'cached_prompt_tokens' => 400,
            'completion_tokens' => 250,
            'reasoning_tokens' => 0,
        ], $driver->lastUsage());
        $this->assertSame('claude-haiku-5-5', $driver->lastModel());
        $this->assertSame('anthropic', $driver->provider());
    }

    public function test_an_empty_slug_is_treated_as_no_slug(): void
    {
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response($this->reply([[
            'content' => 'x', 'wing_slug' => 'work', 'room_slug' => '', 'confidence' => 0.9,
            'propose_new_wing' => false, 'propose_new_room' => false, 'rationale' => '',
        ]]))]);

        $this->assertNull((new AnthropicDigestDriver)->digest('t', [])[0]['room_slug']);
    }

    public function test_the_marked_anthropic_model_wins_over_configuration(): void
    {
        config(['mnemon.digest.anthropic_model' => 'claude-sonnet-5-5']);
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response($this->reply([]))]);

        $this->assertSame('claude-sonnet-5-5', (new AnthropicDigestDriver)->model());

        LlmModel::create(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'is_digest_model' => true]);
        (new AnthropicDigestDriver)->digest('t', []);

        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-opus-5-5');
    }

    public function test_a_marked_model_of_another_provider_is_not_sent_to_anthropic(): void
    {
        LlmModel::create(['provider' => 'openai', 'model' => 'gpt-picked', 'is_digest_model' => true]);

        $this->assertSame('claude-haiku-5-5', (new AnthropicDigestDriver)->model());
    }

    public function test_a_missing_key_is_not_retryable_and_sends_nothing(): void
    {
        config(['services.anthropic.api_key' => null]);
        Http::fake();

        $e = $this->failureOf(fn () => (new AnthropicDigestDriver)->digest('t', []));

        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
        Http::assertNothingSent();
    }

    public function test_overloaded_and_rate_limited_are_retryable_and_a_bad_request_is_not(): void
    {
        $cases = [529 => true, 429 => true, 500 => true, 400 => false, 401 => false];
        $replies = Http::fakeSequence(AnthropicDigestDriver::ENDPOINT);
        foreach (array_keys($cases) as $status) {
            $replies->push(['type' => 'error'], $status);
        }

        foreach ($cases as $status => $retryable) {
            $e = $this->failureOf(fn () => (new AnthropicDigestDriver)->digest('t', []));

            $this->assertSame($retryable, $e->retryable, "HTTP {$status}");
        }
    }

    public function test_a_timeout_is_retryable_without_the_transport_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $e = $this->failureOf(fn () => (new AnthropicDigestDriver)->digest('t', []));

        $this->assertTrue($e->retryable);
        $this->assertStringNotContainsString('cURL', $e->getMessage());
    }

    public function test_an_answer_cut_off_at_max_tokens_is_a_retryable_failure_with_its_usage_kept(): void
    {
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response([
            'content' => [['type' => 'text', 'text' => '{"proposals":[{"content":"half']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 16000],
        ])]);

        $driver = new AnthropicDigestDriver;
        $e = $this->failureOf(fn () => $driver->digest('t', []));

        $this->assertTrue($e->retryable);
        $this->assertSame(16000, $driver->lastUsage()['completion_tokens'], 'a cut-off answer was still paid for');
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
