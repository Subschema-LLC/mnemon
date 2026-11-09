<?php

namespace App\Services;

use App\Contracts\EmbeddingDriverInterface;
use App\Services\Embeddings\GeminiDriver;
use App\Services\Embeddings\NullDriver;
use App\Services\Embeddings\OllamaDriver;
use App\Services\Embeddings\OpenAiCompatibleDriver;
use App\Services\Embeddings\OpenAiDriver;
use App\Services\Embeddings\VoyageDriver;
use InvalidArgumentException;

class EmbeddingManager
{
    public function driver(?string $name = null): EmbeddingDriverInterface
    {
        $name = $name ?? config('mnemon.embedding.driver');

        return match ($name) {
            'openai' => $this->createOpenAiDriver(),
            'ollama' => $this->createOllamaDriver(),
            'openai-compatible' => $this->createOpenAiCompatibleDriver(),
            'voyage' => $this->createVoyageDriver(),
            'gemini' => $this->createGeminiDriver(),
            'none' => new NullDriver,
            default => throw new InvalidArgumentException("Unsupported embedding driver: {$name}"),
        };
    }

    protected function createOpenAiDriver(): OpenAiDriver
    {
        $config = config('mnemon.embedding.drivers.openai');

        return new OpenAiDriver(
            model: $config['model'],
            dimensions: $config['dimensions']
        );
    }

    protected function createOllamaDriver(): OllamaDriver
    {
        $config = config('mnemon.embedding.drivers.ollama');

        return new OllamaDriver(
            model: $config['model'],
            host: $config['host'],
            dimensions: $config['dimensions']
        );
    }

    protected function createOpenAiCompatibleDriver(): OpenAiCompatibleDriver
    {
        $config = config('mnemon.embedding.drivers.openai-compatible');

        // Each has no safe default: the URL and model differ per service, and
        // a guessed width would store vectors that search never finds.
        foreach (['base_url' => 'MNEMON_EMBEDDING_BASE_URL', 'model' => 'MNEMON_EMBEDDING_MODEL', 'dimensions' => 'MNEMON_EMBEDDING_DIMENSIONS'] as $key => $env) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("The openai-compatible embedding driver needs {$env}.");
            }
        }

        $scheme = parse_url($config['base_url'], PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true) || parse_url($config['base_url'], PHP_URL_HOST) === null) {
            throw new InvalidArgumentException('MNEMON_EMBEDDING_BASE_URL must be an http(s) URL, such as https://api.mistral.ai/v1.');
        }

        return new OpenAiCompatibleDriver(
            baseUrl: $config['base_url'],
            apiKey: $config['api_key'] ?? null,
            model: $config['model'],
            dimensions: (int) $config['dimensions'],
        );
    }

    protected function createVoyageDriver(): VoyageDriver
    {
        $config = config('mnemon.embedding.drivers.voyage');

        return new VoyageDriver(
            apiKey: config('services.voyage.api_key'),
            model: $config['model'],
            dimensions: (int) $config['dimensions'],
        );
    }

    protected function createGeminiDriver(): GeminiDriver
    {
        $config = config('mnemon.embedding.drivers.gemini');

        return new GeminiDriver(
            apiKey: config('services.gemini.api_key'),
            model: $config['model'],
            dimensions: (int) $config['dimensions'],
        );
    }
}
