<?php

namespace App\Services\Documents;

use App\Models\DigestUsage;
use App\Services\Digest\DigestDriver;
use App\Services\Digest\DigestDrivers;
use App\Services\Digest\DigestUnavailable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Image captions from the digest provider's vision call: the same provider,
 * key and (unless MNEMON_DOCUMENT_VISION_MODEL says otherwise) model as
 * session digests. Every call is recorded with purpose "vision", so it shows
 * in the LLM models page's spend without moving the digest averages.
 */
class VisionImageCaptioner implements ImageCaptioner
{
    public function __construct(private readonly ?DigestDriver $driver = null) {}

    public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
    {
        $driver = $this->driver ?? DigestDrivers::current();

        try {
            $text = $driver->describeImage($bytes, $mime, $prompt);
        } catch (DigestUnavailable $e) {
            $this->record($driver, $usageLabel, succeeded: false);

            throw $e;
        }

        $this->record($driver, $usageLabel, succeeded: true);

        return ['text' => $text, 'model' => (string) $driver->lastModel(), 'provider' => $driver->provider()];
    }

    private function record(DigestDriver $driver, string $label, bool $succeeded): void
    {
        $usage = $driver->lastUsage();
        if ($usage === null || $driver->lastModel() === null) {
            return;
        }

        // Bookkeeping must not cost the caption it describes.
        try {
            DigestUsage::create([
                'provider' => $driver->provider(),
                'model' => $driver->lastModel(),
                'purpose' => DigestUsage::PURPOSE_VISION,
                'session_id' => $label,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'cached_prompt_tokens' => $usage['cached_prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'reasoning_tokens' => $usage['reasoning_tokens'] ?? 0,
                'succeeded' => $succeeded,
            ]);
        } catch (Throwable $e) {
            Log::warning('mnemon: could not record vision usage', ['reason' => $e->getMessage()]);
        }
    }
}
