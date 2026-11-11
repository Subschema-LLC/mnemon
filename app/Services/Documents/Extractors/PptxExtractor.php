<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\OfficeZip;
use App\Services\Documents\Section;
use App\Services\Documents\Text;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A presentation's slides in the order they are shown, each with its title,
 * its text (tables included) and its speaker notes.
 *
 * The order comes from presentation.xml, not from file names: slide7.xml can
 * be the second slide after a reorder. Hidden slides are read and labelled.
 */
class PptxExtractor implements Extractor
{
    private const P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Placeholders on a notes page that hold no notes. */
    private const NOT_NOTES = ['sldImg', 'sldNum', 'hdr', 'ftr', 'dt'];

    public function extract(string $path, Document $document): Extraction
    {
        $zip = new OfficeZip($path, 'PowerPoint presentation');
        $sections = [];
        $slidePaths = $this->slidePaths($zip);

        foreach ($slidePaths as $index => $slidePath) {
            $slide = $zip->xml($slidePath);
            if ($slide === null) {
                continue;
            }

            $number = $index + 1;
            $xp = $this->xpath($slide);
            $title = $this->title($xp);
            $body = $this->paragraphs($xp, $xp->query('//p:cSld//a:p'));
            $notes = $this->notes($zip, $slidePath);

            $parts = array_filter([
                $title,
                implode("\n", array_values(array_filter($body, fn ($line) => $line !== $title))),
                $notes === '' ? '' : "Notes:\n".$notes,
            ], fn ($part) => $part !== '');

            $text = Text::clean(implode("\n\n", $parts));
            if ($text === '') {
                continue;
            }

            $hidden = $slide->documentElement?->getAttribute('show') === '0';
            $label = 'slide '.$number.($title !== '' ? ': '.mb_strimwidth($title, 0, 60, '…') : '').($hidden ? ' (hidden)' : '');
            $sections[] = new Section($label, $text, 'slide', $number);
        }

        return new Extraction(
            $sections,
            metadata: $zip->coreProperties() + ['slides' => count($slidePaths)],
            reason: $sections === [] ? 'The presentation holds no text.' : null,
        );
    }

    /** @return array<int, string> */
    private function slidePaths(OfficeZip $zip): array
    {
        $presentation = $zip->xml('ppt/presentation.xml')
            ?? throw new ExtractionFailed('The file holds no ppt/presentation.xml; it is not a presentation.');
        $rels = $zip->relationships('ppt/presentation.xml');

        $paths = [];
        foreach ($presentation->getElementsByTagNameNS(self::P, 'sldId') as $sldId) {
            $id = $sldId->getAttributeNS(self::R, 'id');
            if (isset($rels[$id]) && $zip->has($rels[$id]['target'])) {
                $paths[] = $rels[$id]['target'];
            }
        }

        // A presentation.xml without a usable slide list: fall back to the files.
        if ($paths === []) {
            $paths = array_values(array_filter($zip->names(), fn ($n) => preg_match('#^ppt/slides/slide\d+\.xml$#', $n) === 1));
            natsort($paths);
            $paths = array_values($paths);
        }

        return $paths;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('p', self::P);
        $xp->registerNamespace('a', self::A);

        return $xp;
    }

    private function title(DOMXPath $xp): string
    {
        $shape = $xp->query('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]')->item(0);
        if (! $shape instanceof DOMElement) {
            return '';
        }

        return Text::line(implode(' ', $this->paragraphs($xp, $xp->query('.//a:p', $shape))));
    }

    /**
     * @param  iterable<DOMElement>  $paragraphs
     * @return array<int, string>
     */
    private function paragraphs(DOMXPath $xp, iterable $paragraphs): array
    {
        $lines = [];
        foreach ($paragraphs as $p) {
            $text = '';
            foreach ($xp->query('.//a:t | .//a:br', $p) as $node) {
                $text .= $node->localName === 'br' ? "\n" : $node->textContent;
            }
            $text = trim($text);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    private function notes(OfficeZip $zip, string $slidePath): string
    {
        foreach ($zip->relationships($slidePath) as $rel) {
            if (! str_ends_with($rel['type'], '/notesSlide')) {
                continue;
            }

            $notes = $zip->xml($rel['target']);
            if ($notes === null) {
                return '';
            }

            $xp = $this->xpath($notes);
            $lines = [];
            foreach ($xp->query('//p:sp') as $shape) {
                $type = $xp->query('p:nvSpPr/p:nvPr/p:ph/@type', $shape)->item(0)?->nodeValue;
                if ($type !== null && in_array($type, self::NOT_NOTES, true)) {
                    continue;
                }
                array_push($lines, ...$this->paragraphs($xp, $xp->query('.//a:p', $shape)));
            }

            return implode("\n", $lines);
        }

        return '';
    }
}
