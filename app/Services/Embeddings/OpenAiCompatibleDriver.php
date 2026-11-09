<?php

namespace App\Services\Embeddings;

use Illuminate\Http\Client\PendingRequest;

/**
 * Any service with an OpenAI-style embeddings endpoint (POST {base}/embeddings,
 * {model, input} in, data[0].embedding out): Mistral, Together, Fireworks,
 * Jina, DeepInfra, Gemini's OpenAI-compatible endpoint, or a server on your
 * own machine such as vLLM, LM Studio or llama.cpp. The key is optional, for
 * local servers that take none.
 */
class OpenAiCompatibleDriver extends HttpEmbeddingDriver
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        string $model,
        int $dimensions,
    ) {
        parent::__construct($model, $dimensions);
    }

    public function embed(string $text): ?array
    {
        return $this->request(function (PendingRequest $http) use ($text) {
            if (($this->apiKey ?? '') !== '') {
                $http = $http->withToken($this->apiKey);
            }

            return $http->post(rtrim($this->baseUrl, '/').'/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);
        }, 'data.0.embedding');
    }

    protected function provider(): string
    {
        return 'OpenAI-compatible ('.parse_url($this->baseUrl, PHP_URL_HOST).')';
    }

    protected function dimensionsSetting(): string
    {
        return 'MNEMON_EMBEDDING_DIMENSIONS';
    }
}
