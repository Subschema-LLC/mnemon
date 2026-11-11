<?php

namespace Tests\Unit\Services\Digest;

use App\Models\DigestUsage;
use App\Services\Digest\AnthropicDigestDriver;
use App\Services\Digest\DigestUnavailable;
use App\Services\Digest\OpenAiCompatibleDigestDriver;
use App\Services\Digest\OpenAiDigestDriver;
use App\Services\Documents\VisionImageCaptioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The vision call each digest driver makes for an uploaded image, and the
 * usage it records.
 */
class DescribeImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'sk-test',
            'services.anthropic.api_key' => 'sk-ant-test',
            'mnemon.digest.openai_model' => 'gpt-5.4-mini',
            'mnemon.digest.anthropic_model' => null,
            'mnemon.digest.compatible' => ['base_url' => 'https://openrouter.ai/api/v1', 'api_key' => 'or-key', 'model' => 'qwen/qwen3-vl', 'json_mode' => true],
            'mnemon.documents.vision_model' => null,
            'mnemon.documents.vision_max_tokens' => 1234,
        ]);
    }

    private function openAiReply(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 60]];
    }

    public function test_openai_sends_the_image_as_a_data_url(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply('A cat.'))]);

        $text = (new OpenAiDigestDriver)->describeImage('BYTES', 'image/png', 'Describe it.');

        $this->assertSame('A cat.', $text);
        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-5.4-mini'
            && $r['max_completion_tokens'] === 1234
            && ! isset($r['response_format'])
            && $r['messages'][0]['content'][0] === ['type' => 'text', 'text' => 'Describe it.']
            && $r['messages'][0]['content'][1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode('BYTES'));
    }

    public function test_the_vision_model_setting_overrides_the_digest_model(): void
    {
        config(['mnemon.documents.vision_model' => 'gpt-5.4']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply('x'))]);

        $driver = new OpenAiDigestDriver;
        $driver->describeImage('B', 'image/jpeg', 'p');

        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-5.4');
        $this->assertSame('gpt-5.4', $driver->lastModel());
    }

    public function test_anthropic_sends_a_base64_image_block(): void
    {
        Http::fake([AnthropicDigestDriver::ENDPOINT => Http::response([
            'content' => [['type' => 'text', 'text' => 'A chart of revenue.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 80],
        ])]);

        $text = (new AnthropicDigestDriver)->describeImage('BYTES', 'image/jpeg', 'Describe it.');

        $this->assertSame('A chart of revenue.', $text);
        Http::assertSent(fn (Request $r) => $r['max_tokens'] === 1234
            && ! isset($r['output_config'])
            && $r['messages'][0]['content'][0] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('BYTES')]]
            && $r['messages'][0]['content'][1] === ['type' => 'text', 'text' => 'Describe it.']);
    }

    public function test_an_openai_compatible_service_gets_max_tokens(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->openAiReply('x'))]);

        (new OpenAiCompatibleDigestDriver)->describeImage('B', 'image/png', 'p');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $r['max_tokens'] === 1234
            && ! isset($r['max_completion_tokens'])
            && $r['model'] === 'qwen/qwen3-vl');
    }

    public function test_failures_say_whether_a_retry_can_help(): void
    {
        $cases = [
            [['error' => 'bad'], 400, false, 'does the model read images?'],
            [[], 429, true, 'HTTP 429'],
            [[], 503, true, 'HTTP 503'],
            [$this->openAiReply('   '), 200, true, 'no description'],
        ];

        $sequence = Http::sequence();
        foreach ($cases as [$body, $status]) {
            $sequence->push($body, $status);
        }
        Http::fake(['api.openai.com/*' => $sequence]);

        foreach ($cases as [, , $retryable, $message]) {
            try {
                (new OpenAiDigestDriver)->describeImage('B', 'image/png', 'p');
                $this->fail('expected DigestUnavailable');
            } catch (DigestUnavailable $e) {
                $this->assertSame($retryable, $e->retryable, $message);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

    }

    public function test_a_timeout_is_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            (new OpenAiDigestDriver)->describeImage('B', 'image/png', 'p');
            $this->fail('expected DigestUnavailable');
        } catch (DigestUnavailable $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_a_driver_without_a_key_refuses_before_sending(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        try {
            (new OpenAiDigestDriver)->describeImage('B', 'image/png', 'p');
            $this->fail('expected DigestUnavailable');
        } catch (DigestUnavailable) {
            Http::assertNothingSent();
        }
    }

    public function test_the_captioner_records_vision_usage(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply('A cat.'))]);

        $out = (new VisionImageCaptioner(new OpenAiDigestDriver))->caption('B', 'image/png', 'p', 'document:7');

        $this->assertSame(['text' => 'A cat.', 'model' => 'gpt-5.4-mini', 'provider' => 'openai'], $out);
        $usage = DigestUsage::sole();
        $this->assertSame([DigestUsage::PURPOSE_VISION, 'document:7', 800, 60, true],
            [$usage->purpose, $usage->session_id, $usage->prompt_tokens, $usage->completion_tokens, $usage->succeeded]);
    }

    public function test_the_captioner_records_a_call_that_answered_with_nothing_as_failed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply(''))]);

        try {
            (new VisionImageCaptioner(new OpenAiDigestDriver))->caption('B', 'image/png', 'p', 'document:8');
        } catch (DigestUnavailable) {
        }

        $this->assertFalse(DigestUsage::sole()->succeeded);
    }
}
