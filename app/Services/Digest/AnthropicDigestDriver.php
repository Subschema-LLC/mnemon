<?php

namespace App\Services\Digest;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic's Messages API, with structured outputs: the answer is held to
 * the proposals schema by the API itself
 * (https://platform.claude.com/docs/en/build-with-claude/structured-outputs).
 */
class AnthropicDigestDriver extends DigestDriver
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const API_VERSION = '2023-06-01';

    public const DEFAULT_MODEL = 'claude-haiku-5-5';

    public function provider(): string
    {
        return 'anthropic';
    }

    protected function configuredModel(): ?string
    {
        return config('mnemon.digest.anthropic_model') ?: self::DEFAULT_MODEL;
    }

    protected function ensureConfigured(): void
    {
        if (empty(config('services.anthropic.api_key'))) {
            Log::warning('mnemon: ANTHROPIC_API_KEY missing for session_digest');

            throw new DigestUnavailable('ANTHROPIC_API_KEY is not set on the server', retryable: false);
        }
    }

    protected function send(string $model, string $system, string $user): Response
    {
        return Http::timeout((int) config('mnemon.digest.timeout', 60))
            ->withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => self::API_VERSION,
            ])
            ->post(self::ENDPOINT, [
                'model' => $model,
                'max_tokens' => (int) config('mnemon.digest.max_completion_tokens', 16000),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ]);
    }

    protected function sendImage(string $model, string $prompt, string $mime, string $base64): Response
    {
        return Http::timeout((int) config('mnemon.digest.timeout', 60))
            ->withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => self::API_VERSION,
            ])
            ->post(self::ENDPOINT, [
                'model' => $model,
                'max_tokens' => (int) config('mnemon.documents.vision_max_tokens', 2000),
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            ]);
    }

    protected function contentFrom(Response $response): ?string
    {
        $stop = $response->json('stop_reason');
        if ($stop !== null && $stop !== 'end_turn') {
            // max_tokens cuts the JSON short; refusal returns none.
            Log::warning('mnemon: anthropic digest stopped early', ['stop_reason' => $stop]);
        }

        $text = collect($response->json('content') ?? [])
            ->filter(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null))
            ->pluck('text')
            ->implode('');

        return $text === '' ? null : $text;
    }

    protected function usageFrom(Response $response): ?array
    {
        $usage = $response->json('usage');
        if (! is_array($usage)) {
            return null;
        }

        // Anthropic counts cache reads and writes apart from input_tokens;
        // Mnemon's prompt_tokens is all input, cached included.
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        return [
            'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0) + $cacheRead + (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'cached_prompt_tokens' => $cacheRead,
            'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens' => 0,
        ];
    }

    /**
     * The proposals the service reads, as a JSON schema the API enforces.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $proposal = [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string'],
                'wing_slug' => ['type' => ['string', 'null']],
                'room_slug' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'description' => 'From 0.0 to 1.0.'],
                'propose_new_wing' => ['type' => 'boolean'],
                'propose_new_room' => ['type' => 'boolean'],
                'rationale' => ['type' => 'string'],
            ],
            'required' => ['content', 'wing_slug', 'room_slug', 'confidence', 'propose_new_wing', 'propose_new_room', 'rationale'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => ['proposals' => ['type' => 'array', 'items' => $proposal]],
            'required' => ['proposals'],
            'additionalProperties' => false,
        ];
    }
}
