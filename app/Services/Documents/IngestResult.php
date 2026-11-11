<?php

namespace App\Services\Documents;

use App\Models\Document;

/**
 * An upload's outcome. `created` is false when the same file was already
 * stored in that room: the existing document is returned, extracted again if
 * its last attempt had failed.
 */
final class IngestResult
{
    public function __construct(
        public readonly Document $document,
        public readonly bool $created,
    ) {}

    /**
     * The shape every entry point reports.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $doc = $this->document->loadMissing('room.wing');

        return [
            'id' => $doc->id,
            'name' => $doc->original_name,
            'type' => $doc->type,
            'size' => $doc->size,
            'status' => $doc->status,
            'error' => $doc->error,
            'wing' => $doc->room?->wing?->slug,
            'room' => $doc->room?->slug,
            'chunks' => $doc->chunk_count,
            'drawer_ids' => $doc->drawers()->pluck('id')->all(),
            'created' => $this->created,
        ];
    }
}
