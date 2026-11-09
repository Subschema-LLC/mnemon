<?php

namespace App\Services\Digest;

use App\Models\LlmModel;
use RuntimeException;

/**
 * Which digest driver runs. The provider of the model marked for digests in
 * the admin panel decides, as the marked model already wins over the
 * configured one; with none marked, MNEMON_DIGEST_DRIVER does.
 */
class DigestDrivers
{
    /** Provider => what an operator calls it. */
    public const LABELS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'openai-compatible' => 'OpenAI-compatible',
    ];

    public static function current(): DigestDriver
    {
        return self::make(LlmModel::digestModel()?->provider ?? (string) config('mnemon.digest.driver', 'openai'));
    }

    public static function make(string $provider): DigestDriver
    {
        return match ($provider) {
            'openai' => new OpenAiDigestDriver,
            'anthropic' => new AnthropicDigestDriver,
            'openai-compatible' => new OpenAiCompatibleDigestDriver,
            default => throw new RuntimeException("Unknown digest driver: {$provider}"),
        };
    }

    /**
     * The server setting a provider still needs before it can digest, or
     * null when it has what it needs (or is not a digest provider).
     */
    public static function missingSetting(string $provider): ?string
    {
        return match ($provider) {
            'openai' => empty(config('services.openai.api_key') ?? env('OPENAI_API_KEY')) ? 'OPENAI_API_KEY' : null,
            'anthropic' => empty(config('services.anthropic.api_key')) ? 'ANTHROPIC_API_KEY' : null,
            'openai-compatible' => empty(config('mnemon.digest.compatible.base_url')) ? 'MNEMON_DIGEST_BASE_URL' : null,
            default => null,
        };
    }
}
