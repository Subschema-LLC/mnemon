<?php

namespace Tests\Concerns;

use ZipArchive;

/**
 * Small, real files for the document tests, built on the fly: Word and
 * PowerPoint packages with ZipArchive, a PDF with a correct cross-reference
 * table, images with GD. Each is written to a temporary path that is removed
 * when the test ends.
 */
trait BuildsDocuments
{
    /** @var array<int, string> */
    private array $builtFiles = [];

    protected function tearDownBuildsDocuments(): void
    {
        foreach ($this->builtFiles as $path) {
            @unlink($path);
        }
        $this->builtFiles = [];
    }

    protected function tempFile(string $contents, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mnemon-test-').$suffix;
        file_put_contents($path, $contents);
        $this->builtFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<string, string>  $parts  path in the package => contents
     */
    protected function zipFile(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mnemon-zip-');
        $this->builtFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($parts as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * A Word document. Each block is a string (a paragraph), ['h1' => text],
     * ['h2' => text], ['li' => text], ['table' => [[cells], ...]] or ['xml' => raw body XML].
     *
     * @param  array<int, string|array<string, mixed>>  $blocks
     * @param  array<string, string>  $extraParts
     */
    protected function docx(array $blocks, array $extraParts = [], ?string $title = null): string
    {
        $body = '';
        foreach ($blocks as $block) {
            $body .= match (true) {
                is_string($block) => $this->wP($block),
                isset($block['h1']) => $this->wP($block['h1'], 'Heading1'),
                isset($block['h2']) => $this->wP($block['h2'], 'Heading2'),
                isset($block['h3']) => $this->wP($block['h3'], 'Heading3'),
                isset($block['title']) => $this->wP($block['title'], 'Title'),
                isset($block['li']) => '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>'.htmlspecialchars($block['li']).'</w:t></w:r></w:p>',
                isset($block['table']) => '<w:tbl>'.implode('', array_map(
                    fn (array $row) => '<w:tr>'.implode('', array_map(fn ($c) => '<w:tc>'.$this->wP((string) $c).'</w:tc>', $row)).'</w:tr>',
                    $block['table'],
                )).'</w:tbl>',
                isset($block['xml']) => $block['xml'],
            };
        }

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006">'
                .'<w:body>'.$body.'<w:sectPr/></w:body></w:document>',
        ];
        if ($title !== null) {
            $parts['docProps/core.xml'] = $this->coreXml($title);
        }

        return $this->zipFile($parts + $extraParts);
    }

    private function wP(string $text, ?string $style = null): string
    {
        $pPr = $style !== null ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

        return '<w:p>'.$pPr.'<w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r></w:p>';
    }

    private function coreXml(string $title, string $creator = 'Test Author'): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.htmlspecialchars($title).'</dc:title><dc:creator>'.htmlspecialchars($creator).'</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">2026-01-02T03:04:05Z</dcterms:created>'
            .'</cp:coreProperties>';
    }

    /**
     * A presentation. Each slide: ['title' => ?, 'body' => [lines], 'notes' => ?, 'hidden' => bool].
     * $order lists the slide files in presentation order (default 1..n), so
     * a reordered deck can be built.
     *
     * @param  array<int, array<string, mixed>>  $slides
     * @param  array<int, int>|null  $order
     */
    protected function pptx(array $slides, ?array $order = null, ?string $title = null): string
    {
        $p = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $relType = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $order ??= range(1, count($slides));

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
        ];

        $sldIds = '';
        $presRels = '';
        foreach ($order as $i => $n) {
            $sldIds .= '<p:sldId id="'.(256 + $i).'" r:id="rId'.($i + 1).'"/>';
            $presRels .= '<Relationship Id="rId'.($i + 1).'" Type="'.$relType.'/slide" Target="slides/slide'.$n.'.xml"/>';
        }
        $parts['ppt/presentation.xml'] = '<?xml version="1.0" encoding="UTF-8"?><p:presentation '.$p.'><p:sldIdLst>'.$sldIds.'</p:sldIdLst></p:presentation>';
        $parts['ppt/_rels/presentation.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$presRels.'</Relationships>';

        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $shapes = '';
            if (isset($slide['title'])) {
                $shapes .= '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
                    .'<p:txBody><a:p><a:r><a:t>'.htmlspecialchars($slide['title']).'</a:t></a:r></a:p></p:txBody></p:sp>';
            }
            if (! empty($slide['body'])) {
                $paras = implode('', array_map(fn ($l) => '<a:p><a:r><a:t>'.htmlspecialchars($l).'</a:t></a:r></a:p>', $slide['body']));
                $shapes .= '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr/><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr><p:txBody>'.$paras.'</p:txBody></p:sp>';
            }
            $show = ! empty($slide['hidden']) ? ' show="0"' : '';
            $parts["ppt/slides/slide{$n}.xml"] = '<?xml version="1.0" encoding="UTF-8"?><p:sld '.$p.$show.'><p:cSld><p:spTree>'.$shapes.'</p:spTree></p:cSld></p:sld>';

            if (isset($slide['notes'])) {
                $parts["ppt/slides/_rels/slide{$n}.xml.rels"] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId9" Type="'.$relType.'/notesSlide" Target="../notesSlides/notesSlide'.$n.'.xml"/></Relationships>';
                $parts["ppt/notesSlides/notesSlide{$n}.xml"] = '<?xml version="1.0" encoding="UTF-8"?><p:notes '.$p.'><p:cSld><p:spTree>'
                    .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Image"/><p:cNvSpPr/><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr></p:sp>'
                    .'<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes"/><p:cNvSpPr/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.htmlspecialchars($slide['notes']).'</a:t></a:r></a:p></p:txBody></p:sp>'
                    .'<p:sp><p:nvSpPr><p:cNvPr id="4" name="Number"/><p:cNvSpPr/><p:nvPr><p:ph type="sldNum"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$n.'</a:t></a:r></a:p></p:txBody></p:sp>'
                    .'</p:spTree></p:cSld></p:notes>';
            }
        }

        if ($title !== null) {
            $parts['docProps/core.xml'] = $this->coreXml($title);
        }

        return $this->zipFile($parts);
    }

    /**
     * A PDF with one page per string, in Helvetica. An empty string makes a
     * page with no text at all.
     *
     * @param  array<int, string>  $pages
     */
    protected function pdf(array $pages, ?string $title = null): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 4;
        $pageObjects = [];
        foreach ($pages as $text) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "{$pageId} 0 R";
            $stream = $text === '' ? '' : 'BT /F1 12 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
            $pageObjects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $pageObjects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects += $pageObjects;

        $infoId = null;
        if ($title !== null) {
            $infoId = $next++;
            $objects[$infoId] = '<< /Title ('.addcslashes($title, '()\\').') /Author (Test Author) >>';
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R".($infoId !== null ? " /Info {$infoId} 0 R" : '')." >>\nstartxref\n{$xref}\n%%EOF\n";

        return $this->tempFile($pdf);
    }

    /** A solid-colour image, encoded as png, jpg or webp. */
    protected function image(string $type = 'png', int $width = 40, int $height = 30): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));

        ob_start();
        match ($type) {
            'png' => imagepng($img),
            'jpg' => imagejpeg($img),
            'webp' => imagewebp($img),
        };

        return $this->tempFile((string) ob_get_clean());
    }
}
