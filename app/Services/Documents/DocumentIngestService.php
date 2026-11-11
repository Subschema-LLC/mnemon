<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\Wing;
use App\Services\Digest\DigestUnavailable;
use App\Services\Documents\Extractors\CsvExtractor;
use App\Services\Documents\Extractors\DocxExtractor;
use App\Services\Documents\Extractors\ImageExtractor;
use App\Services\Documents\Extractors\PdfExtractor;
use App\Services\Documents\Extractors\PptxExtractor;
use App\Services\Documents\Extractors\SvgExtractor;
use App\Services\DrawerWriteService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Uploads, from whichever door they came in by: the admin panel, the HTTP
 * endpoint or document_add. Callers check wing access first; this files into
 * whatever wing it is given.
 *
 * The original is stored, then its text extracted and split into drawers
 * written through DrawerWriteService, so redaction, deduplication and
 * embedding are exactly what any other drawer gets. A failure to extract
 * keeps the original and records why, so the document can be tried again.
 */
class DocumentIngestService
{
    public function __construct(
        private readonly DrawerWriteService $drawers,
        private readonly Chunker $chunker,
    ) {}

    /**
     * @param  string  $path  The uploaded file, wherever the request left it
     * @param  string  $originalName  The name it was uploaded under
     * @param  string  $wingSlug  A wing the caller may write to; created if missing
     * @param  string|null  $roomSlug  Defaults to MNEMON_DOCUMENT_ROOM
     *
     * @throws DocumentRejected when nothing was stored
     */
    public function ingest(
        string $path,
        string $originalName,
        string $wingSlug,
        ?string $roomSlug,
        string $source,
        ?string $note = null,
        ?CarbonInterface $occurredAt = null,
    ): IngestResult {
        $name = self::cleanName($originalName);
        $size = @filesize($path);

        if ($size === false) {
            throw new DocumentRejected('The upload could not be read.');
        }
        if ($size === 0) {
            throw new DocumentRejected("{$name} is empty.");
        }
        $maxMb = max(1, (int) config('mnemon.documents.max_mb', 25));
        if ($size > $maxMb * 1024 * 1024) {
            throw new DocumentRejected("{$name} is larger than {$maxMb} MB, the most Mnemon accepts (MNEMON_DOCUMENT_MAX_MB).");
        }

        $type = DocumentType::detect($path, $name);
        $sha = (string) hash_file('sha256', $path);

        $wingSlug = Wing::slugify($wingSlug);
        if ($wingSlug === '') {
            throw new DocumentRejected('The wing must contain at least one letter or digit.');
        }
        $roomSlug = Wing::slugify($roomSlug ?? '') ?: Wing::slugify((string) config('mnemon.documents.default_room', 'documents'));

        $room = $this->drawers->roomFor($wingSlug, $roomSlug);
        $note = filled($note) ? mb_substr(trim((string) $note), 0, 5000) : null;

        $existing = Document::where('room_id', $room->id)->where('sha256', $sha)->first();
        if ($existing !== null) {
            if ($this->needsProcessing($existing)) {
                $this->process($existing);
            }

            return new IngestResult($existing->fresh(), false);
        }

        $disk = (string) config('mnemon.documents.disk', 'local');
        $stored = "documents/{$sha}.{$type->value}";
        if (! Storage::disk($disk)->exists($stored)) {
            $stream = fopen($path, 'rb');
            try {
                if (! Storage::disk($disk)->put($stored, $stream)) {
                    throw new DocumentRejected("{$name} could not be saved on the server.");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $document = Document::create([
            'room_id' => $room->id,
            'original_name' => $name,
            'type' => $type->value,
            'mime' => $type->mime(),
            'size' => $size,
            'sha256' => $sha,
            'disk' => $disk,
            'path' => $stored,
            'note' => $note,
            'source' => mb_substr($source, 0, 255),
            'occurred_at' => $occurredAt !== null && $occurredAt->isFuture() ? now() : $occurredAt,
        ]);

        $this->process($document);

        return new IngestResult($document->fresh(), true);
    }

    /**
     * Extract a stored document's text and file it, replacing any drawers an
     * earlier attempt made. Used for a new upload and for a retry.
     */
    public function process(Document $document): Document
    {
        @set_time_limit(self::timeLimit());

        if ($document->status !== Document::STATUS_PROCESSING) {
            $document->update(['status' => Document::STATUS_PROCESSING, 'error' => null]);
        }

        $local = null;

        try {
            $local = $this->localCopy($document);
            $extraction = $this->extractorFor($document)->extract($local['path'], $document);
        } catch (ExtractionFailed $e) {
            return $this->fail($document, $e->getMessage());
        } catch (DigestUnavailable $e) {
            return $this->fail($document, 'The image could not be described: '.$e->getMessage().($e->retryable ? '. Retry the document.' : '.'));
        } catch (Throwable $e) {
            Log::error('mnemon: document extraction failed', [
                'document_id' => $document->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->fail($document, 'Extraction failed unexpectedly; the server log has the details.');
        } finally {
            if ($local !== null && $local['temporary']) {
                @unlink($local['path']);
            }
        }

        $chunks = $this->chunker->chunk(
            $extraction->sections,
            (int) config('mnemon.documents.chunk_chars', 4000),
            $extraction->mergeable,
        );

        $maxChunks = max(1, (int) config('mnemon.documents.max_chunks', 200));
        if (count($chunks) > $maxChunks) {
            return $this->fail($document, sprintf(
                'The document would make %d drawers, more than the %d allowed (MNEMON_DOCUMENT_MAX_CHUNKS). Split it, or raise the limit and retry.',
                count($chunks), $maxChunks,
            ), $extraction->metadata);
        }

        $noText = $chunks === [];
        if ($noText && filled($document->note)) {
            $chunks = [new Section('note', (string) $document->note)];
        }

        $room = $document->room()->with('wing')->first();
        if ($room === null || $room->wing === null) {
            return $this->fail($document, 'The room this document was filed in no longer exists.', $extraction->metadata);
        }

        DB::transaction(function () use ($document, $room, $chunks, $extraction, $noText) {
            $document->drawers()->get()->each->delete();

            foreach (array_values($chunks) as $i => $chunk) {
                $this->drawers->createDrawer(
                    wingSlug: $room->wing->slug,
                    roomSlug: $room->slug,
                    content: $this->drawerText($document, $chunk, $i, count($chunks), $extraction, $noText),
                    source: (string) ($document->source ?: 'document_upload'),
                    // Flat, scalar values: the admin renders drawer metadata as
                    // key-value pairs and cannot show a nested array.
                    metadata: array_filter([
                        'captured_via' => 'document_upload',
                        'document_id' => $document->id,
                        'document_name' => $document->original_name,
                        'document_type' => $document->type,
                        'part' => $i + 1,
                        'parts' => count($chunks),
                        'section' => $chunk->label,
                        'generated_by' => $noText ? null : $extraction->generatedBy,
                    ], fn ($v) => $v !== null),
                    occurredAt: $document->occurred_at,
                    documentId: $document->id,
                    deduplicate: false,
                );
            }

            $document->update([
                'status' => $noText ? Document::STATUS_NO_TEXT : Document::STATUS_PROCESSED,
                'error' => $noText ? ($extraction->reason ?? 'No text was found.') : null,
                'chunk_count' => count($chunks),
                'metadata' => $extraction->metadata ?: null,
            ]);
        });

        return $document->fresh();
    }

    /**
     * Remove an upload: its drawers (soft-deleted, as any drawer is), its row,
     * and the stored file once no other document shares it.
     */
    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document->drawers()->get()->each->delete();
            $document->delete();
        });

        $shared = Document::where('disk', $document->disk)->where('path', $document->path)->exists();
        if (! $shared) {
            Storage::disk($document->disk)->delete($document->path);
        }
    }

    /**
     * A failed document is tried again when the same file is uploaded again,
     * and so is one left "processing" past the time limit: the request that
     * was extracting it died.
     */
    public function needsProcessing(Document $document): bool
    {
        return $document->status === Document::STATUS_FAILED
            || ($document->status === Document::STATUS_PROCESSING
                && $document->updated_at !== null
                && $document->updated_at->lt(now()->subSeconds(self::timeLimit() + 60)));
    }

    public static function timeLimit(): int
    {
        return max(30, (int) config('mnemon.documents.time_limit', 300));
    }

    public static function cleanName(string $name): string
    {
        // A browser may send a path; keep the last part, on either separator.
        $name = basename(str_replace('\\', '/', $name));
        $name = Text::line($name);

        return mb_substr($name !== '' ? $name : 'upload', 0, 200);
    }

    private function drawerText(Document $document, Section $chunk, int $index, int $total, Extraction $extraction, bool $noText): string
    {
        $what = DocumentType::from($document->type)->isImage() ? 'Image' : 'Document';
        $header = "{$what}: {$document->original_name}";

        if ($noText) {
            return "{$header}\n\nNote: {$chunk->text}";
        }

        if ($total > 1) {
            $header .= " · {$chunk->label} (".($index + 1)." of {$total})";
        }

        if ($extraction->generatedBy !== null) {
            $header .= "\nDescription written by {$extraction->generatedBy}, not text from the file.";
        }

        if ($index === 0 && filled($document->note)) {
            $header .= "\nNote: ".$document->note;
        }

        return "{$header}\n\n{$chunk->text}";
    }

    private function extractorFor(Document $document): Extractor
    {
        $type = DocumentType::from($document->type);

        return match (true) {
            $type === DocumentType::Docx => app(DocxExtractor::class),
            $type === DocumentType::Pptx => app(PptxExtractor::class),
            $type === DocumentType::Pdf => app(PdfExtractor::class),
            $type === DocumentType::Csv => app(CsvExtractor::class),
            $type === DocumentType::Svg => app(SvgExtractor::class),
            $type->isImage() => app(ImageExtractor::class),
        };
    }

    /**
     * A path the parsers can open. The local disk has one already; any other
     * disk (S3) is copied down first and the copy removed afterwards.
     *
     * @return array{path: string, temporary: bool}
     */
    private function localCopy(Document $document): array
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new ExtractionFailed('The stored original is missing from the server.');
        }

        try {
            return ['path' => $disk->path($document->path), 'temporary' => false];
        } catch (Throwable) {
            $tmp = tempnam(sys_get_temp_dir(), 'mnemon-doc-');
            file_put_contents($tmp, $disk->readStream($document->path));

            return ['path' => $tmp, 'temporary' => true];
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fail(Document $document, string $message, array $metadata = []): Document
    {
        $document->update([
            'status' => Document::STATUS_FAILED,
            'error' => $message,
            'metadata' => $metadata ?: $document->metadata,
        ]);

        return $document->fresh();
    }
}
