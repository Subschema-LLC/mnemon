<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbedsQueries;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Google's Gemini embeddings (models/{model}:embedContent). Stored text is
 * embedded for retrieval as a document and searches as a query. How that is
 * said depends on the model: gemini-embedding-001 takes a taskType field;
 * gemini-embedding-2 refuses it and reads the task from a prefix on the text
 * (https://ai.google.dev/gemini-api/docs/embeddings).
 *
 * gemini-embedding-001 returns unit-length vectors only at its full width
 * (3072), so every vector is normalised here; for models that already
 * normalise, this changes nothing.
 */
class GeminiDriver extends HttpEmbeddingDriver implements EmbedsQueries
{
    public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private ?string $apiKey,
        string $model,
        int $dimensions,
    ) {
        // The model is part of the URL path.
        if (preg_match('/^[a-z0-9][a-z0-9.-]*$/', $model) !== 1) {
            throw new InvalidArgumentException("Not a Gemini model name: {$model}");
        }
        parent::__construct($model, $dimensions);
    }

    public function embed(string $text): ?array
    {
        return $this->usesTaskType()
            ? $this->send($text, 'RETRIEVAL_DOCUMENT')
            : $this->send('title: none | text: '.$text);
    }

    public function embedQuery(string $text): ?array
    {
        return $this->usesTaskType()
            ? $this->send($text, 'RETRIEVAL_QUERY')
            : $this->send('task: search result | query: '.$text);
    }

    /**
     * The older models take taskType; gemini-embedding-2 and later take the
     * task as a text prefix instead.
     */
    private function usesTaskType(): bool
    {
        return $this->model === 'gemini-embedding-001'
            || str_starts_with($this->model, 'gemini-embedding-001-')
            || str_starts_with($this->model, 'text-embedding-')
            || str_starts_with($this->model, 'embedding-');
    }

    private function send(string $text, ?string $taskType = null): ?array
    {
        if (($this->apiKey ?? '') === '') {
            Log::warning('Gemini embedding skipped: GEMINI_API_KEY is not set');

            return null;
        }

        $body = [
            'model' => 'models/'.$this->model,
            'content' => ['parts' => [['text' => $text]]],
            'outputDimensionality' => $this->dimensions,
        ];
        if ($taskType !== null) {
            $body['taskType'] = $taskType;
        }

        $vector = $this->request(fn (PendingRequest $http) => $http->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post(self::BASE_URL.$this->model.':embedContent', $body), 'embedding.values');

        return $vector === null ? null : self::normalize($vector);
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn (float $v) => $v / $norm, $vector) : $vector;
    }

    protected function provider(): string
    {
        return 'Gemini';
    }

    protected function dimensionsSetting(): string
    {
        return 'MNEMON_GEMINI_DIMENSIONS';
    }
}
