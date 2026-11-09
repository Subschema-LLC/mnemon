<?php

namespace App\Contracts;

/**
 * A driver whose provider embeds search queries and stored documents
 * differently (Voyage's input_type, Gemini's taskType). embed() embeds what
 * is stored; embedQuery() embeds what is searched for. Matching the two is
 * what these providers' retrieval quality is measured on.
 */
interface EmbedsQueries
{
    /** @return array<int, float>|null */
    public function embedQuery(string $text): ?array;
}
