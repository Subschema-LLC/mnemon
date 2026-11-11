<?php

namespace App\Services\Digest;

use Illuminate\Support\Facades\Log;

/**
 * Any service with an OpenAI-style Chat Completions API: OpenRouter, Groq,
 * Together, Mistral, DeepSeek, Gemini's OpenAI endpoint, or a local Ollama,
 * vLLM or LM Studio. Sends max_tokens, which these services take more widely
 * than OpenAI's newer max_completion_tokens, and a JSON-mode request that
 * can be turned off for servers that refuse it.
 */
class OpenAiCompatibleDigestDriver extends OpenAiDigestDriver
{
    public function provider(): string
    {
        return 'openai-compatible';
    }

    protected function configuredModel(): ?string
    {
        return config('mnemon.digest.compatible.model');
    }

    protected function ensureConfigured(): void
    {
        $baseUrl = (string) config('mnemon.digest.compatible.base_url');
        if ($baseUrl === '' || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            Log::warning('mnemon: MNEMON_DIGEST_BASE_URL missing or not an http(s) URL for session_digest');

            throw new DigestUnavailable('MNEMON_DIGEST_BASE_URL is not set on the server', retryable: false);
        }

        if ($this->model() === '') {
            Log::warning('mnemon: no model for the openai-compatible digest driver');

            throw new DigestUnavailable('no digest model is set: mark one in the admin panel or set MNEMON_DIGEST_MODEL', retryable: false);
        }
    }

    protected function apiKey(): ?string
    {
        return config('mnemon.digest.compatible.api_key');
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('mnemon.digest.compatible.base_url'), '/').'/chat/completions';
    }

    protected function body(string $model, string $system, string $user): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => (int) config('mnemon.digest.max_completion_tokens', 16000),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (config('mnemon.digest.compatible.json_mode', true)) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
    }

    /**
     * The OpenAI image shape, with max_tokens for the same reason body() uses it.
     */
    protected function imageBody(string $model, string $prompt, string $mime, string $base64): array
    {
        $body = parent::imageBody($model, $prompt, $mime, $base64);
        unset($body['max_completion_tokens']);
        $body['max_tokens'] = (int) config('mnemon.documents.vision_max_tokens', 2000);

        return $body;
    }
}
