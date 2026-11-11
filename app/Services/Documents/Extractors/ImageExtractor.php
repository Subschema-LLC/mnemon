<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\ImageCaptioner;
use App\Services\Documents\ImagePreparer;
use App\Services\Documents\Section;
use App\Services\Documents\Text;

/**
 * A png, jpg or webp, described by a vision model: one paid call per image.
 *
 * The description is generated text, not the file's own, and the drawer says
 * so. With MNEMON_DOCUMENT_VISION off nothing is sent and the image is stored
 * with no text; a note given with the upload still becomes a drawer.
 */
class ImageExtractor implements Extractor
{
    public function __construct(private readonly ImageCaptioner $captioner) {}

    public function extract(string $path, Document $document): Extraction
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new ExtractionFailed('The image could not be read.');
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new ExtractionFailed('The image could not be decoded; it may be damaged.');
        }
        $metadata = ['width' => $size[0], 'height' => $size[1]];

        if (! config('mnemon.documents.vision')) {
            return new Extraction([], metadata: $metadata, reason: 'Image descriptions are off (MNEMON_DOCUMENT_VISION).');
        }

        $prepared = ImagePreparer::forVision($bytes, $document->mime, max(256, (int) config('mnemon.documents.vision_max_edge', 1568)));

        $caption = $this->captioner->caption(
            $prepared['bytes'],
            $prepared['mime'],
            $this->prompt($document),
            'document:'.$document->id,
        );

        return new Extraction(
            [new Section('description', Text::clean($caption['text']))],
            metadata: $metadata + ['vision_model' => $caption['model'], 'vision_provider' => $caption['provider']],
            generatedBy: $caption['model'],
        );
    }

    private function prompt(Document $document): string
    {
        $context = 'File name: '.$document->original_name;
        if (filled($document->note)) {
            $context .= "\nNote from the person who uploaded it: ".mb_substr((string) $document->note, 0, 2000);
        }

        return <<<PROMPT
You are describing an image for a private, searchable knowledge base. The text you write is all that will be searchable, so be specific.

1. If the image contains legible text, transcribe it first, verbatim, keeping its structure (headings, lists, table rows).
2. Then describe what the image shows: what kind of image it is (photo, screenshot, diagram, chart, document scan, whiteboard...), its subject, and the details someone might later search for. For a chart or diagram, give its structure, labels and values. For a screenshot, name the application and what is on screen.
3. Do not identify real people from their faces. Describe people by role or appearance only, unless a visible caption or name tag names them.

Write plain text with no preamble and no closing remarks.

{$context}
PROMPT;
    }
}
