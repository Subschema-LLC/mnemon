<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\SafeXml;
use App\Services\Documents\Section;
use App\Services\Documents\Text;
use DOMElement;

/**
 * The words in an SVG: its title and description, its text elements, and the
 * labels written for screen readers.
 *
 * An SVG is not described by a vision model: the vision APIs do not take SVG,
 * and rasterising it would need an image library the stack does not ship. An
 * SVG with no text in it is stored, and says so.
 */
class SvgExtractor implements Extractor
{
    public function extract(string $path, Document $document): Extraction
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ExtractionFailed('The SVG file could not be read.');
        }

        $svg = SafeXml::load($raw, 'SVG image');
        $root = $svg->documentElement;
        if (! $root instanceof DOMElement || strcasecmp($root->localName, 'svg') !== 0) {
            throw new ExtractionFailed('The file is not an SVG image: its root element is not <svg>.');
        }

        $lines = [];
        $add = function (string $prefix, string $text) use (&$lines) {
            $text = Text::line($text);
            if ($text !== '') {
                $lines[$prefix.$text] = $prefix.$text;
            }
        };

        foreach (['title' => 'Title: ', 'desc' => 'Description: '] as $tag => $prefix) {
            foreach ($svg->getElementsByTagNameNS('*', $tag) as $node) {
                $add($prefix, $node->textContent);
            }
        }

        // A <text>'s content includes its <tspan>s; a <text> inside another is
        // read with its parent.
        foreach ($svg->getElementsByTagNameNS('*', 'text') as $node) {
            if (! ($node->parentNode instanceof DOMElement && $node->parentNode->localName === 'text')) {
                $add('', $node->textContent);
            }
        }

        foreach ($svg->getElementsByTagNameNS('*', '*') as $node) {
            if ($node->hasAttribute('aria-label')) {
                $add('Label: ', $node->getAttribute('aria-label'));
            }
        }

        $metadata = array_filter([
            'width' => $root->getAttribute('width') ?: null,
            'height' => $root->getAttribute('height') ?: null,
            'view_box' => $root->getAttribute('viewBox') ?: null,
        ]);

        if ($lines === []) {
            return new Extraction([], metadata: $metadata, reason: 'The SVG holds no text. Vision models do not read SVG.');
        }

        return new Extraction([new Section('image', Text::clean(implode("\n", $lines)))], metadata: $metadata);
    }
}
