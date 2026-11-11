<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\OfficeZip;
use App\Services\Documents\Section;
use App\Services\Documents\Text;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A Word document's body text, in order: paragraphs, list items and tables.
 *
 * Headings become Markdown headings, and each top-level heading starts a
 * section, so a long document is split where its author split it. What a
 * reader would not see is left out: text deleted under tracked changes
 * (w:delText) and field codes (w:instrText) are not w:t, so they are never
 * read. Headers, footers, footnotes and comments are not read either.
 */
class DocxExtractor implements Extractor
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    public function extract(string $path, Document $document): Extraction
    {
        $zip = new OfficeZip($path, 'Word document');
        $xml = $zip->xml('word/document.xml')
            ?? throw new ExtractionFailed('The file holds no word/document.xml; it is not a Word document.');

        $xp = new DOMXPath($xml);
        $xp->registerNamespace('w', self::W);
        $xp->registerNamespace('mc', self::MC);
        $body = $xp->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            throw new ExtractionFailed('The Word document has no body.');
        }

        /** @var array<int, array{text: string, level: int|null}> $blocks */
        $blocks = [];
        $this->walk($body, $xp, $blocks);

        $sections = [];
        $label = null;
        $lines = [];
        $flush = function () use (&$sections, &$label, &$lines) {
            $text = Text::clean(implode("\n\n", $lines));
            if ($text !== '') {
                $sections[] = new Section($label ?? 'section '.(count($sections) + 1), $text, 'section', count($sections) + 1);
            }
            $lines = [];
        };

        foreach ($blocks as $block) {
            if ($block['level'] !== null && $block['level'] <= 2) {
                $flush();
                $label = mb_strimwidth(Text::line($block['text']), 0, 80, '…');
            }
            $lines[] = $block['level'] !== null
                ? str_repeat('#', min(6, $block['level'])).' '.Text::line($block['text'])
                : $block['text'];
        }
        $flush();

        return new Extraction(
            $sections,
            metadata: $zip->coreProperties(),
            reason: $sections === [] ? 'The Word document holds no text.' : null,
        );
    }

    /**
     * @param  array<int, array{text: string, level: int|null}>  $blocks
     */
    private function walk(DOMNode $parent, DOMXPath $xp, array &$blocks): void
    {
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::W) {
                continue;
            }

            match ($node->localName) {
                'p' => $this->paragraph($node, $xp, $blocks),
                'tbl' => $this->table($node, $xp, $blocks),
                'sdt' => ($content = $xp->query('w:sdtContent', $node)->item(0)) ? $this->walk($content, $xp, $blocks) : null,
                default => null,
            };
        }
    }

    /**
     * @param  array<int, array{text: string, level: int|null}>  $blocks
     */
    private function paragraph(DOMElement $p, DOMXPath $xp, array &$blocks): void
    {
        $text = trim($this->runs($p, $xp));
        if ($text === '') {
            return;
        }

        $level = $this->headingLevel($p, $xp);
        if ($level === null && $xp->query('w:pPr/w:numPr', $p)->length > 0) {
            $text = '- '.$text;
        }

        $blocks[] = ['text' => $text, 'level' => $level];
    }

    /**
     * @param  array<int, array{text: string, level: int|null}>  $blocks
     */
    private function table(DOMElement $tbl, DOMXPath $xp, array &$blocks): void
    {
        $rows = [];
        foreach ($xp->query('w:tr', $tbl) as $tr) {
            $cells = [];
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $parts = [];
                foreach ($xp->query('.//w:p', $tc) as $p) {
                    $part = trim($this->runs($p, $xp));
                    if ($part !== '') {
                        $parts[] = $part;
                    }
                }
                $cells[] = Text::line(implode(' ', $parts));
            }
            if (implode('', $cells) !== '') {
                $rows[] = implode(' | ', $cells);
            }
        }

        if ($rows !== []) {
            $blocks[] = ['text' => implode("\n", $rows), 'level' => null];
        }
    }

    /**
     * The visible text of a paragraph: its runs, tabs and line breaks, in
     * order. A text box is written twice, as a modern shape and as an
     * mc:Fallback copy for older readers; only the first is read.
     */
    private function runs(DOMElement $p, DOMXPath $xp): string
    {
        $text = '';
        $nodes = $xp->query('(.//w:t | .//w:tab | .//w:br | .//w:cr | .//w:noBreakHyphen)[not(ancestor::mc:Fallback)]', $p);
        foreach ($nodes as $node) {
            $text .= match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                'br', 'cr' => "\n",
                'noBreakHyphen' => '-',
            };
        }

        return $text;
    }

    /** 1 for a title or first-level heading, and so on; null for body text. */
    private function headingLevel(DOMElement $p, DOMXPath $xp): ?int
    {
        $style = $xp->query('w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? '';
        if (strcasecmp($style, 'Title') === 0) {
            return 1;
        }
        // Built-in style ids are localised in some editions ("berschrift1").
        if (preg_match('/^(?:heading|berschrift|titre|titolo|kop|rubrik)\s*(\d)$/i', $style, $m)) {
            return (int) $m[1];
        }

        $outline = $xp->query('w:pPr/w:outlineLvl/@w:val', $p)->item(0)?->nodeValue;

        return $outline !== null && ctype_digit($outline) && (int) $outline < 9 ? (int) $outline + 1 : null;
    }
}
