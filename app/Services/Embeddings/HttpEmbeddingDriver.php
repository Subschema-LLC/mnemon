<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingDriverInterface;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What the hosted embedding drivers share: the timeout, the error handling
 * (a failed call returns null and is logged, so a drawer is still written
 * without an embedding), and a check that the vector is as wide as Mnemon
 * is configured for. A wider or narrower vector would be stored but never
 * found: semantic search only compares vectors of the configured width.
 */
abstract class HttpEmbeddingDriver implements EmbeddingDriverInterface
{
    public function __construct(
        protected string $model,
        protected int $dimensions,
    ) {}

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /** The provider's name in log lines. */
    abstract protected function provider(): string;

    /** The setting that sets the width, named in the mismatch error. */
    abstract protected function dimensionsSetting(): string;

    /**
     * @param  Closure(PendingRequest): Response  $send
     * @return array<int, float>|null
     */
    protected function request(Closure $send, string $vectorPath): ?array
    {
        try {
            // An explicit, short budget: the default is 30s, and a drawer
            // write waits on this call.
            $response = $send(Http::timeout((int) config('mnemon.embedding.timeout', 10))->acceptJson());

            if ($response->failed()) {
                Log::error("{$this->provider()} embedding failed", [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $vector = $response->json($vectorPath);
            if (! is_array($vector) || $vector === [] || ! array_is_list($vector)) {
                Log::error("{$this->provider()} embedding response held no vector", ['body' => Str::limit($response->body(), 200)]);

                return null;
            }

            if (count($vector) !== $this->dimensions) {
                Log::error("{$this->provider()} returned ".count($vector)."-dimension embeddings, but Mnemon is set for {$this->dimensions}. "
                    ."Set {$this->dimensionsSetting()} to ".count($vector).', then run php artisan mnemon:reembed.', ['model' => $this->model]);

                return null;
            }

            return array_map('floatval', $vector);
        } catch (\Exception $e) {
            Log::error("{$this->provider()} embedding exception", ['message' => $e->getMessage()]);

            return null;
        }
    }
}
