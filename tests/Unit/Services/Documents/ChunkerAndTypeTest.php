<?php

namespace Tests\Unit\Services\Documents;

use App\Services\Documents\Chunker;
use App\Services\Documents\DocumentRejected;
use App\Services\Documents\DocumentType;
use App\Services\Documents\Section;
use App\Services\Documents\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class ChunkerAndTypeTest extends TestCase
{
    use BuildsDocuments;

    // -------------------------------------------------------------- chunker

    public function test_short_neighbouring_slides_merge_under_a_range_label(): void
    {
        $sections = [
            new Section('slide 1: A', 'alpha', 'slide', 1),
            new Section('slide 2: B', 'beta', 'slide', 2),
            new Section('slide 3: C', 'gamma', 'slide', 3),
        ];

        $chunks = (new Chunker)->chunk($sections, 4000);

        $this->assertCount(1, $chunks);
        $this->assertSame('slides 1–3', $chunks[0]->label);
        $this->assertSame("[slide 1: A]\nalpha\n\n[slide 2: B]\nbeta\n\n[slide 3: C]\ngamma", $chunks[0]->text);
    }

    public function test_merged_document_sections_are_labelled_first_to_last(): void
    {
        $chunks = (new Chunker)->chunk([
            new Section('Goals', 'g', 'section', 1),
            new Section('Risks', 'r', 'section', 2),
        ], 4000);

        $this->assertSame('Goals – Risks', $chunks[0]->label);
    }

    public function test_merging_stops_at_the_limit_counting_labels(): void
    {
        $sections = array_map(fn ($i) => new Section("page {$i}", str_repeat('x', 150), 'page', $i), range(1, 5));

        $chunks = (new Chunker)->chunk($sections, 400);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(400, mb_strlen($chunk->text));
        }
        $this->assertSame(['pages 1–2', 'pages 3–4', 'page 5'], array_map(fn ($c) => $c->label, $chunks));
    }

    public function test_a_long_section_splits_at_paragraphs_then_sentences_then_words(): void
    {
        $paragraph = str_repeat('word ', 50).'end.';
        $text = implode("\n\n", array_fill(0, 6, $paragraph));

        $chunks = (new Chunker)->chunk([new Section('Body', $text, 'section', 1)], 600, mergeable: false);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual(600, mb_strlen($chunk->text));
            $this->assertSame('Body (part '.($i + 1).' of '.count($chunks).')', $chunk->label);
            $this->assertStringEndsWith('end.', $chunk->text, 'cut at a paragraph boundary');
        }
        $this->assertSame(substr_count($text, 'word'), array_sum(array_map(fn ($c) => substr_count($c->text, 'word'), $chunks)), 'nothing lost');
    }

    public function test_text_with_no_break_is_cut_hard_without_splitting_characters(): void
    {
        $text = str_repeat('é', 1000);

        $chunks = (new Chunker)->chunk([new Section('x', $text)], 300, mergeable: false);

        $this->assertSame([300, 300, 300, 100], array_map(fn ($c) => mb_strlen($c->text), $chunks));
        $this->assertTrue(mb_check_encoding(implode('', array_map(fn ($c) => $c->text, $chunks)), 'UTF-8'));
    }

    public function test_no_sections_make_no_chunks(): void
    {
        $this->assertSame([], (new Chunker)->chunk([], 4000));
    }

    // ----------------------------------------------------------------- text

    public function test_text_clean_normalises_what_extraction_leaves_behind(): void
    {
        $this->assertSame("a b\n\nc", Text::clean("\u{FEFF}a\u{00A0}b  \r\n\r\n\r\n\r\nc\u{200B}\x07 "));
    }

    // ----------------------------------------------------------------- type

    public static function detected(): array
    {
        return [
            'png' => ['png', 'logo.png', DocumentType::Png],
            'jpg named jpeg' => ['jpg', 'photo.JPEG', DocumentType::Jpg],
            'webp' => ['webp', 'shot.webp', DocumentType::Webp],
        ];
    }

    #[DataProvider('detected')]
    public function test_images_are_detected_from_their_bytes(string $kind, string $name, DocumentType $expected): void
    {
        $this->assertSame($expected, DocumentType::detect($this->image($kind), $name));
    }

    public function test_office_files_pdfs_csvs_and_svgs_are_detected(): void
    {
        $this->assertSame(DocumentType::Docx, DocumentType::detect($this->docx(['x']), 'a.docx'));
        $this->assertSame(DocumentType::Pptx, DocumentType::detect($this->pptx([['title' => 'x']]), 'a.pptx'));
        $this->assertSame(DocumentType::Pdf, DocumentType::detect($this->pdf(['x']), 'a.pdf'));
        $this->assertSame(DocumentType::Csv, DocumentType::detect($this->tempFile("a,b\n1,2\n"), 'a.csv'));
        $this->assertSame(DocumentType::Svg, DocumentType::detect($this->tempFile('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'), 'a.svg'));
    }

    public static function refused(): array
    {
        return [
            'unsupported extension' => ['a,b', 'notes.txt', 'Mnemon does not read .txt files'],
            'no extension' => ['a,b', 'README', 'The file has no extension'],
            'png named as pdf' => ['PNG', 'report.pdf', 'its content is not a PDF'],
            'a zip named docx' => ['ZIP', 'report.docx', 'its content is not a Word document'],
            'a pdf named png' => ['PDF', 'logo.png', 'its content is not a PNG image'],
            'binary named csv' => ["a,b\0\1\2", 'data.csv', 'its content is not a CSV file'],
            'html named svg' => ['<html><script>x</script></html>', 'x.svg', 'its content is not an SVG image'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_file_whose_bytes_do_not_match_its_name_is_refused(string $contents, string $name, string $message): void
    {
        $path = match ($contents) {
            'PNG' => $this->image('png'),
            'ZIP' => $this->zipFile(['hello.txt' => 'hi']),
            'PDF' => $this->pdf(['x']),
            default => $this->tempFile($contents),
        };

        try {
            DocumentType::detect($path, $name);
            $this->fail('expected a refusal');
        } catch (DocumentRejected $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
