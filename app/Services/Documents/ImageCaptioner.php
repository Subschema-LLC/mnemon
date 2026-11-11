<?php

namespace App\Services\Documents;

use App\Services\Digest\DigestUnavailable;

/**
 * Turns an image into searchable text. Bound in the container so tests, and
 * an installation with its own vision service, can replace it.
 */
interface ImageCaptioner
{
    /**
     * @param  string  $usageLabel  What the call is recorded against, e.g. "document:12"
     * @return array{text: string, model: string, provider: string}
     *
     * @throws DigestUnavailable when the model could not be asked or gave no answer
     */
    public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array;
}
