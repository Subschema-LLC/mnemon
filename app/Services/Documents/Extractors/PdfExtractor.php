<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\Section;
use App\Services\Documents\Text;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * A PDF's text layer, page by page, read with smalot/pdfparser (pure PHP, so
 * no system package is needed).
 *
 * A scanned PDF has no text layer -- its pages are pictures -- and comes back
 * with no sections and a reason saying so; it is not OCR'd. Nor is an
 * encrypted one read.
 */
class PdfExtractor implements Extractor
{
    public function extract(string $path, Document $document): Extraction
    {
        $config = new Config;
        // Images are never read here; keeping them would only cost memory.
        $config->setRetainImageContent(false);
        // Per-stream ceiling on decompressed content, against compression bombs.
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $pdf = (new Parser([], $config))->parseFile($path);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'secured') !== false) {
                throw new ExtractionFailed('The PDF is encrypted, and Mnemon does not read encrypted PDFs.');
            }

            Log::info('mnemon: a PDF could not be parsed', ['document_id' => $document->id, 'message' => $message]);

            throw new ExtractionFailed('The PDF could not be read; it may be damaged.');
        }

        $sections = [];
        $unreadable = 0;
        $pages = $pdf->getPages();

        foreach ($pages as $index => $page) {
            try {
                $text = Text::clean($page->getText());
            } catch (Throwable) {
                $unreadable++;

                continue;
            }

            if ($text !== '') {
                $sections[] = new Section('page '.($index + 1), $text, 'page', $index + 1);
            }
        }

        $details = $pdf->getDetails();
        $metadata = array_filter([
            'pages' => count($pages),
            'title' => is_string($details['Title'] ?? null) ? mb_substr(Text::line($details['Title']), 0, 255) : null,
            'author' => is_string($details['Author'] ?? null) ? mb_substr(Text::line($details['Author']), 0, 255) : null,
            'unreadable_pages' => $unreadable ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        return new Extraction(
            $sections,
            metadata: $metadata,
            reason: $sections === []
                ? ($unreadable > 0
                    ? 'None of the PDF\'s pages could be read.'
                    : 'The PDF has no text layer (a scanned document?). Scanned PDFs are not read yet.')
                : null,
        );
    }
}
