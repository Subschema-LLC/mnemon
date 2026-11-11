<?php

namespace App\Services\Documents;

/**
 * What an extractor read from a file.
 *
 * No sections means the file holds no text; `reason` says why, for the person
 * who uploaded it. `mergeable` is false when the sections are already sized
 * to a drawer and carry something of their own -- a CSV chunk repeats the
 * header row, and two merged would repeat it mid-text. `generatedBy` names
 * the model when the text was written by one (an image description) rather
 * than taken from the file.
 */
final class Extraction
{
    /**
     * @param  array<int, Section>  $sections
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $metadata = [],
        public readonly bool $mergeable = true,
        public readonly ?string $generatedBy = null,
        public readonly ?string $reason = null,
    ) {}
}
