<?php

namespace App\Services\Documents;

/**
 * One natural part of a document: a page, a slide, a run of rows, the text
 * under a heading. `kind` and `number` let neighbouring parts merged into one
 * drawer be labelled as a range ("pages 3–5").
 */
final class Section
{
    public function __construct(
        public readonly string $label,
        public readonly string $text,
        public readonly ?string $kind = null,
        public readonly ?int $number = null,
    ) {}
}
