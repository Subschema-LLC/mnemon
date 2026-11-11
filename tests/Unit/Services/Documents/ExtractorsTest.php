<?php

namespace Tests\Unit\Services\Documents;

use App\Models\Document;
use App\Services\Digest\DigestUnavailable;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractors\CsvExtractor;
use App\Services\Documents\Extractors\DocxExtractor;
use App\Services\Documents\Extractors\ImageExtractor;
use App\Services\Documents\Extractors\PdfExtractor;
use App\Services\Documents\Extractors\PptxExtractor;
use App\Services\Documents\Extractors\SvgExtractor;
use App\Services\Documents\ImageCaptioner;
use App\Services\Documents\OfficeZip;
use App\Services\Documents\Section;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class ExtractorsTest extends TestCase
{
    use BuildsDocuments;

    private function doc(string $type, string $mime = 'application/octet-stream', ?string $note = null): Document
    {
        return new Document(['original_name' => "file.{$type}", 'type' => $type, 'mime' => $mime, 'note' => $note]);
    }

    /** @param array<int, Section> $sections */
    private function texts(array $sections): array
    {
        return array_map(fn (Section $s) => $s->text, $sections);
    }

    // ----------------------------------------------------------------- docx

    public function test_docx_reads_paragraphs_lists_and_tables_in_order_and_splits_at_headings(): void
    {
        $path = $this->docx([
            'A preamble paragraph.',
            ['h1' => 'Goals'],
            'Ship uploads.',
            ['li' => 'first item'],
            ['h3' => 'A minor heading'],
            ['table' => [['Name', 'Owner'], ['Uploads', 'Scott']]],
            ['h2' => 'Risks'],
            'Scanned PDFs.',
        ], title: 'Quarterly plan');

        $out = (new DocxExtractor)->extract($path, $this->doc('docx'));

        $this->assertSame(['section 1', 'Goals', 'Risks'], array_map(fn ($s) => $s->label, $out->sections));
        $this->assertSame('A preamble paragraph.', $out->sections[0]->text);
        $this->assertSame("# Goals\n\nShip uploads.\n\n- first item\n\n### A minor heading\n\nName | Owner\nUploads | Scott", $out->sections[1]->text);
        $this->assertSame("## Risks\n\nScanned PDFs.", $out->sections[2]->text);
        $this->assertSame('Quarterly plan', $out->metadata['title']);
        $this->assertSame('Test Author', $out->metadata['author']);
    }

    public function test_docx_skips_deleted_text_field_codes_and_text_box_fallbacks(): void
    {
        $path = $this->docx([['xml' => '<w:p>'
            .'<w:r><w:t>kept</w:t></w:r>'
            .'<w:del><w:r><w:delText>DELETED</w:delText></w:r></w:del>'
            .'<w:r><w:instrText>HYPERLINK "x"</w:instrText></w:r>'
            .'<w:r><w:tab/><w:t>after tab</w:t></w:r>'
            .'<w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:t> boxed</w:t></mc:Choice>'
            .'<mc:Fallback><w:t> FALLBACK</w:t></mc:Fallback></mc:AlternateContent></w:r>'
            .'</w:p>']]);

        $out = (new DocxExtractor)->extract($path, $this->doc('docx'));

        $this->assertSame(["kept\tafter tab boxed"], $this->texts($out->sections));
    }

    public function test_an_empty_docx_has_no_sections_and_says_why(): void
    {
        $out = (new DocxExtractor)->extract($this->docx([]), $this->doc('docx'));

        $this->assertSame([], $out->sections);
        $this->assertSame('The Word document holds no text.', $out->reason);
    }

    public function test_a_docx_with_a_doctype_entity_attack_reads_no_external_file(): void
    {
        $secret = $this->tempFile('TOPSECRET-XXE');
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://'.$secret.'">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>a &x; b</w:t></w:r></w:p></w:body></w:document>';
        $path = $this->zipFile(['word/document.xml' => $xml]);

        try {
            $out = (new DocxExtractor)->extract($path, $this->doc('docx'));
            $this->assertStringNotContainsString('TOPSECRET', implode(' ', $this->texts($out->sections)));
        } catch (ExtractionFailed $e) {
            $this->assertStringNotContainsString('TOPSECRET', $e->getMessage());
        }
    }

    public function test_a_billion_laughs_docx_is_refused_without_expanding(): void
    {
        $entities = '<!ENTITY a0 "lol">';
        for ($i = 1; $i <= 9; $i++) {
            $entities .= '<!ENTITY a'.$i.' "'.str_repeat('&a'.($i - 1).';', 10).'">';
        }
        $xml = '<?xml version="1.0"?><!DOCTYPE d ['.$entities.']>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&a9;</w:t></w:r></w:p></w:body></w:document>';
        $path = $this->zipFile(['word/document.xml' => $xml]);

        $before = memory_get_usage();
        try {
            $out = (new DocxExtractor)->extract($path, $this->doc('docx'));
            $this->assertLessThan(10000, mb_strlen(implode('', $this->texts($out->sections))));
        } catch (ExtractionFailed) {
            // Refusing is as good as not expanding.
        }
        $this->assertLessThan(50 * 1024 * 1024, memory_get_usage() - $before);
    }

    public function test_a_simple_literal_entity_is_still_substituted(): void
    {
        // Illustrator and some Office exporters declare entities this way.
        $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY ns_w "http://schemas.openxmlformats.org/wordprocessingml/2006/main">]>'
            .'<w:document xmlns:w="&ns_w;"><w:body><w:p><w:r><w:t>hello</w:t></w:r></w:p></w:body></w:document>';

        $out = (new DocxExtractor)->extract($this->zipFile(['word/document.xml' => $xml]), $this->doc('docx'));

        $this->assertSame(['hello'], $this->texts($out->sections));
    }

    public function test_a_zip_without_a_word_document_fails_with_a_reason(): void
    {
        $this->expectException(ExtractionFailed::class);

        (new DocxExtractor)->extract($this->zipFile(['other.txt' => 'x']), $this->doc('docx'));
    }

    public function test_a_part_larger_than_the_limit_is_refused(): void
    {
        $path = $this->zipFile(['word/document.xml' => str_repeat(' ', OfficeZip::MAX_PART_BYTES + 1)]);

        $this->expectException(ExtractionFailed::class);
        (new DocxExtractor)->extract($path, $this->doc('docx'));
    }

    // ----------------------------------------------------------------- pptx

    public function test_pptx_follows_the_presentation_order_with_titles_notes_and_hidden_slides(): void
    {
        $path = $this->pptx([
            ['title' => 'Intro', 'body' => ['Welcome'], 'notes' => 'Say hello'],
            ['title' => 'Roadmap', 'body' => ['Q1: uploads', 'Q2: OCR'], 'hidden' => true],
            ['body' => []],
        ], order: [2, 1, 3], title: 'Deck');

        $out = (new PptxExtractor)->extract($path, $this->doc('pptx'));

        $this->assertSame(['slide 1: Roadmap (hidden)', 'slide 2: Intro'], array_map(fn ($s) => $s->label, $out->sections));
        $this->assertSame("Roadmap\n\nQ1: uploads\nQ2: OCR", $out->sections[0]->text);
        $this->assertSame("Intro\n\nWelcome\n\nNotes:\nSay hello", $out->sections[1]->text);
        $this->assertSame(3, $out->metadata['slides']);
        $this->assertSame('Deck', $out->metadata['title']);
        $this->assertSame([1, 2], array_map(fn ($s) => $s->number, $out->sections));
    }

    public function test_pptx_notes_leave_out_the_slide_number_placeholder(): void
    {
        $out = (new PptxExtractor)->extract($this->pptx([['title' => 'T', 'notes' => 'speaker note']]), $this->doc('pptx'));

        $this->assertStringEndsWith("Notes:\nspeaker note", $out->sections[0]->text);
    }

    // ------------------------------------------------------------------ pdf

    public function test_pdf_reads_text_per_page(): void
    {
        $out = (new PdfExtractor)->extract($this->pdf(['First page words', '', 'Third page words'], title: 'Report'), $this->doc('pdf'));

        $this->assertSame(['page 1', 'page 3'], array_map(fn ($s) => $s->label, $out->sections));
        $this->assertStringContainsString('First page words', $out->sections[0]->text);
        $this->assertStringContainsString('Third page words', $out->sections[1]->text);
        $this->assertSame(3, $out->metadata['pages']);
        $this->assertSame('Report', $out->metadata['title']);
    }

    public function test_a_pdf_without_text_says_it_may_be_scanned(): void
    {
        $out = (new PdfExtractor)->extract($this->pdf(['']), $this->doc('pdf'));

        $this->assertSame([], $out->sections);
        $this->assertStringContainsString('no text layer', $out->reason);
    }

    public function test_a_damaged_pdf_fails_with_a_reason(): void
    {
        $this->expectException(ExtractionFailed::class);
        $this->expectExceptionMessage('may be damaged');

        (new PdfExtractor)->extract($this->tempFile("%PDF-1.4\nthis is not a pdf"), $this->doc('pdf'));
    }

    // ------------------------------------------------------------------ csv

    public function test_csv_chunks_repeat_the_header_and_are_not_merged(): void
    {
        config(['mnemon.documents.chunk_chars' => 500]);
        $rows = "name,city\n";
        for ($i = 1; $i <= 60; $i++) {
            $rows .= "person {$i},\"Springfield, IL\"\n";
        }

        $out = (new CsvExtractor)->extract($this->tempFile($rows), $this->doc('csv'));

        $this->assertFalse($out->mergeable);
        $this->assertGreaterThan(1, count($out->sections));
        foreach ($out->sections as $section) {
            $this->assertStringStartsWith("name,city\n", $section->text);
            $this->assertLessThanOrEqual(500, mb_strlen($section->text));
        }
        $this->assertStringStartsWith('rows 1–', $out->sections[0]->label);
        $this->assertStringEndsWith('of 60', $out->sections[0]->label);
        $this->assertStringContainsString('"Springfield, IL"', $out->sections[0]->text);
        $this->assertSame(['rows' => 60, 'columns' => 2, 'delimiter' => ','], $out->metadata);
    }

    public function test_csv_sniffs_semicolons_strips_a_bom_and_reads_windows_1252(): void
    {
        $path = $this->tempFile("\xEF\xBB\xBFa;b\nx;y\n");
        $out = (new CsvExtractor)->extract($path, $this->doc('csv'));
        $this->assertSame(["a;b\nx;y"], $this->texts($out->sections));

        $latin = (new CsvExtractor)->extract($this->tempFile("name\ncaf\xE9\n"), $this->doc('csv'));
        $this->assertSame(["name\ncafé"], $this->texts($latin->sections));
    }

    public function test_a_header_only_csv_keeps_its_header(): void
    {
        $out = (new CsvExtractor)->extract($this->tempFile("only,a,header\n"), $this->doc('csv'));

        $this->assertSame(['only,a,header'], $this->texts($out->sections));
    }

    // ------------------------------------------------------------------ svg

    public function test_svg_reads_title_description_text_and_labels(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50" viewBox="0 0 100 50">'
            .'<title>Org chart</title><desc>Who reports to whom</desc>'
            .'<g aria-label="Engineering"><text x="1" y="1">Scott <tspan>Fillman</tspan></text></g>'
            .'<text>Scott Fillman</text>'
            .'<script>alert(1)</script></svg>';

        $out = (new SvgExtractor)->extract($this->tempFile($svg), $this->doc('svg'));

        $text = $out->sections[0]->text;
        $this->assertStringContainsString('Org chart', $text);
        $this->assertStringContainsString('Who reports to whom', $text);
        $this->assertStringContainsString('Engineering', $text);
        $this->assertSame(1, substr_count($text, 'Scott Fillman'), 'duplicates are dropped');
        $this->assertStringNotContainsString('alert', $text);
        $this->assertSame('100', $out->metadata['width']);
    }

    public function test_an_svg_with_no_text_says_so(): void
    {
        $out = (new SvgExtractor)->extract($this->tempFile('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'), $this->doc('svg'));

        $this->assertSame([], $out->sections);
        $this->assertStringContainsString('holds no text', $out->reason);
    }

    public function test_an_svg_with_an_external_entity_reads_no_file(): void
    {
        $secret = $this->tempFile('TOPSECRET-SVG');
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file://'.$secret.'">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

        try {
            $out = (new SvgExtractor)->extract($this->tempFile($svg), $this->doc('svg'));
            $this->assertStringNotContainsString('TOPSECRET', implode(' ', $this->texts($out->sections)));
        } catch (ExtractionFailed $e) {
            $this->assertStringNotContainsString('TOPSECRET', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- image

    public function test_an_image_is_described_by_the_captioner_and_marked_as_generated(): void
    {
        $captioner = new class implements ImageCaptioner
        {
            public array $calls = [];

            public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
            {
                $this->calls[] = compact('mime', 'prompt', 'usageLabel') + ['size' => getimagesizefromstring($bytes)];

                return ['text' => "A red square.\n", 'model' => 'vision-model', 'provider' => 'openai'];
            }
        };
        config(['mnemon.documents.vision' => true, 'mnemon.documents.vision_max_edge' => 256]);

        $out = (new ImageExtractor($captioner))->extract($this->image('png', 1000, 500), $this->doc('png', 'image/png', note: 'the logo draft'));

        $this->assertSame(['A red square.'], $this->texts($out->sections));
        $this->assertSame('vision-model', $out->generatedBy);
        $this->assertSame(['width' => 1000, 'height' => 500, 'vision_model' => 'vision-model', 'vision_provider' => 'openai'], $out->metadata);

        $call = $captioner->calls[0];
        $this->assertSame('image/png', $call['mime']);
        $this->assertSame([256, 128], [$call['size'][0], $call['size'][1]], 'scaled to the longest edge');
        $this->assertStringContainsString('the logo draft', $call['prompt']);
        $this->assertStringContainsString('Do not identify real people', $call['prompt']);
    }

    public function test_jpeg_and_webp_are_sent_as_jpeg(): void
    {
        $captioner = new class implements ImageCaptioner
        {
            public ?string $mime = null;

            public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
            {
                $this->mime = $mime;

                return ['text' => 'x', 'model' => 'm', 'provider' => 'p'];
            }
        };
        config(['mnemon.documents.vision' => true]);

        (new ImageExtractor($captioner))->extract($this->image('webp'), $this->doc('webp', 'image/webp'));
        $this->assertSame('image/jpeg', $captioner->mime);
    }

    public function test_with_vision_off_an_image_is_not_sent(): void
    {
        $captioner = new class implements ImageCaptioner
        {
            public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
            {
                throw new \LogicException('must not be called');
            }
        };
        config(['mnemon.documents.vision' => false]);

        $out = (new ImageExtractor($captioner))->extract($this->image('jpg'), $this->doc('jpg', 'image/jpeg'));

        $this->assertSame([], $out->sections);
        $this->assertStringContainsString('MNEMON_DOCUMENT_VISION', $out->reason);
    }

    public function test_a_failed_caption_propagates(): void
    {
        $captioner = new class implements ImageCaptioner
        {
            public function caption(string $bytes, string $mime, string $prompt, string $usageLabel): array
            {
                throw new DigestUnavailable('the vision call failed with HTTP 500', retryable: true);
            }
        };
        config(['mnemon.documents.vision' => true]);

        $this->expectException(DigestUnavailable::class);
        (new ImageExtractor($captioner))->extract($this->image(), $this->doc('png', 'image/png'));
    }
}
