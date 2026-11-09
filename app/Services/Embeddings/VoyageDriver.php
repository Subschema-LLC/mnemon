<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbedsQueries;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * Voyage AI (https://docs.voyageai.com/reference/embeddings-api). Stored
 * text is embedded as a document and searches as a query, which is how
 * Voyage's retrieval models are meant to be used.
 */
class VoyageDriver extends HttpEmbeddingDriver implements EmbedsQueries
{
    public const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

    /** The width Voyage's current models return unless asked for another. */
    public const DEFAULT_DIMENSIONS = 1024;

    public function __construct(
        private ?string $apiKey,
        string $model,
        int $dimensions,
    ) {
        parent::__construct($model, $dimensions);
    }

    public function embed(string $text): ?array
    {
        return $this->send($text, 'document');
    }

    public function embedQuery(string $text): ?array
    {
        return $this->send($text, 'query');
    }

    private function send(string $text, string $inputType): ?array
    {
        if (($this->apiKey ?? '') === '') {
            Log::warning('Voyage embedding skipped: VOYAGE_API_KEY is not set');

            return null;
        }

        return $this->request(fn (PendingRequest $http) => $http->withToken($this->apiKey)->post(self::ENDPOINT, array_filter([
            'model' => $this->model,
            'input' => $text,
            'input_type' => $inputType,
            // Only when asked for another width: older models take no output_dimension.
            'output_dimension' => $this->dimensions !== self::DEFAULT_DIMENSIONS ? $this->dimensions : null,
        ], fn ($v) => $v !== null)), 'data.0.embedding');
    }

    protected function provider(): string
    {
        return 'Voyage';
    }

    protected function dimensionsSetting(): string
    {
        return 'MNEMON_VOYAGE_DIMENSIONS';
    }
}
