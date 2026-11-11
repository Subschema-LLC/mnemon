<?php

namespace App\Services\Digest;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI's Chat Completions API. OpenAiCompatibleDigestDriver reuses it for
 * other services with the same API.
 */
class OpenAiDigestDriver extends DigestDriver
{
    public function provider(): string
    {
        return 'openai';
    }

    protected function configuredModel(): ?string
    {
        return config('mnemon.digest.openai_model') ?: config('mnemon.llm.model', 'gpt-5.6-luna');
    }

    protected function ensureConfigured(): void
    {
        if (empty($this->apiKey())) {
            Log::warning('mnemon: OPENAI_API_KEY missing for session_digest');

            throw new DigestUnavailable('OPENAI_API_KEY is not set on the server', retryable: false);
        }
    }

    protected function apiKey(): ?string
    {
        return config('services.openai.api_key') ?? env('OPENAI_API_KEY');
    }

    protected function endpoint(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(string $model, string $system, string $user): array
    {
        return [
            'model' => $model,
            'max_completion_tokens' => (int) config('mnemon.digest.max_completion_tokens', 16000),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
    }

    protected function send(string $model, string $system, string $user): Response
    {
        return $this->post($this->body($model, $system, $user));
    }

    protected function sendImage(string $model, string $prompt, string $mime, string $base64): Response
    {
        return $this->post($this->imageBody($model, $prompt, $mime, $base64));
    }

    /**
     * @return array<string, mixed>
     */
    protected function imageBody(string $model, string $prompt, string $mime, string $base64): array
    {
        return [
            'model' => $model,
            'max_completion_tokens' => (int) config('mnemon.documents.vision_max_tokens', 2000),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(array $body): Response
    {
        $http = Http::timeout((int) config('mnemon.digest.timeout', 60));
        if (($key = $this->apiKey()) !== null && $key !== '') {
            $http = $http->withToken($key);
        }

        return $http->post($this->endpoint(), $body);
    }

    protected function contentFrom(Response $response): ?string
    {
        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : null;
    }

    protected function usageFrom(Response $response): ?array
    {
        $usage = $response->json('usage');
        if (! is_array($usage)) {
            return null;
        }

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'cached_prompt_tokens' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
        ];
    }
}
