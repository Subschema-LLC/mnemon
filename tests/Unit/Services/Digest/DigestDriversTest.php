<?php

namespace Tests\Unit\Services\Digest;

use App\Models\LlmModel;
use App\Services\Digest\AnthropicDigestDriver;
use App\Services\Digest\DigestDriver;
use App\Services\Digest\DigestDrivers;
use App\Services\Digest\OpenAiCompatibleDigestDriver;
use App\Services\Digest\OpenAiDigestDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DigestDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_configured_driver_runs_when_no_model_is_marked(): void
    {
        foreach (['openai' => OpenAiDigestDriver::class, 'anthropic' => AnthropicDigestDriver::class, 'openai-compatible' => OpenAiCompatibleDigestDriver::class] as $name => $class) {
            config(['mnemon.digest.driver' => $name]);

            $this->assertInstanceOf($class, DigestDrivers::current());
        }
    }

    public function test_the_marked_models_provider_wins_over_the_configured_driver(): void
    {
        config(['mnemon.digest.driver' => 'openai']);
        LlmModel::create(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'is_digest_model' => true]);

        $driver = DigestDrivers::current();

        $this->assertInstanceOf(AnthropicDigestDriver::class, $driver);
        $this->assertSame('claude-sonnet-5-5', $driver->model());
    }

    public function test_a_price_comparison_row_cannot_be_marked_and_so_never_picks_the_driver(): void
    {
        config(['mnemon.digest.driver' => 'openai']);
        $row = LlmModel::create(['provider' => 'mistral', 'model' => 'mistral-large', 'is_digest_model' => true]);

        $this->assertFalse($row->fresh()->is_digest_model);
        $this->assertInstanceOf(OpenAiDigestDriver::class, DigestDrivers::current());
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        DigestDrivers::make('cohere');
    }

    public function test_missing_settings_are_named_per_provider(): void
    {
        config(['services.openai.api_key' => 'sk', 'services.anthropic.api_key' => null, 'mnemon.digest.compatible.base_url' => null]);

        $this->assertNull(DigestDrivers::missingSetting('openai'));
        $this->assertSame('ANTHROPIC_API_KEY', DigestDrivers::missingSetting('anthropic'));
        $this->assertSame('MNEMON_DIGEST_BASE_URL', DigestDrivers::missingSetting('openai-compatible'));
        $this->assertNull(DigestDrivers::missingSetting('mistral'));
    }

    public function test_decode_json_takes_off_fences_and_reasoning_and_gives_up_on_prose(): void
    {
        $this->assertSame(['a' => 1], DigestDriver::decodeJson('{"a":1}'));
        $this->assertSame(['a' => 1], DigestDriver::decodeJson("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], DigestDriver::decodeJson("```\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], DigestDriver::decodeJson("<think>\nhmm {not json}\n</think>\n{\"a\":1}"));
        $this->assertNull(DigestDriver::decodeJson('not json'));
        $this->assertNull(DigestDriver::decodeJson(null));
    }
}
