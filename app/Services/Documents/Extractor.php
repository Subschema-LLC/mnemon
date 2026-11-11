<?php

namespace App\Services\Documents;

use App\Models\Document;

interface Extractor
{
    /**
     * @throws ExtractionFailed when the file cannot be read
     */
    public function extract(string $path, Document $document): Extraction;
}
