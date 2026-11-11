<?php

namespace App\Services\Documents;

use DOMDocument;

/**
 * XML from an uploaded file, parsed so the file cannot reach anything else.
 *
 * Office files and SVGs are XML, and XML can declare entities: an external
 * one reads a file or a URL into the text (XXE), and nested internal ones
 * expand a few bytes into gigabytes. The DOCTYPE is where every entity is
 * declared, so it is removed before parsing, and no flag that substitutes
 * entities is passed. Network access is off too.
 *
 * One kind of declaration is kept: a plain literal (`<!ENTITY ns_svg
 * "http://www.w3.org/2000/svg">`, as Adobe Illustrator writes). Its value may
 * hold no `&`, `<` or `%`, so it cannot refer to another entity, and it is
 * substituted in a single pass with a ceiling on the result -- linear, never
 * exponential. A reference to anything else is left undefined, and the file
 * fails to parse: refused rather than read.
 */
final class SafeXml
{
    private const MAX_ENTITY_VALUE = 256;

    public static function load(string $xml, string $what): DOMDocument
    {
        $entities = [];

        if (preg_match('/<!DOCTYPE[^>\[]*+(?:\[([^\]]*+)\])?[^>]*+>/i', $xml, $doctype)) {
            preg_match_all(
                '/<!ENTITY\s+([A-Za-z_][\w.-]*+)\s+(["\'])([^"\'&<%]{0,'.self::MAX_ENTITY_VALUE.'})\2\s*>/',
                $doctype[1] ?? '',
                $declared,
                PREG_SET_ORDER,
            );
            foreach ($declared as [, $name, , $value]) {
                $entities['&'.$name.';'] = $value;
            }

            $xml = (string) preg_replace('/<!DOCTYPE[^>\[]*+(?:\[[^\]]*+\])?[^>]*+>/i', '', $xml, 1);
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ExtractionFailed("The {$what} declares XML entities in a form Mnemon does not read.");
        }

        if ($entities !== []) {
            // Measured before substituting, so the expansion is never built.
            $growth = 0;
            foreach ($entities as $ref => $value) {
                $growth += substr_count($xml, $ref) * max(0, strlen($value) - strlen($ref));
            }
            if ($growth > strlen($xml) + 1024 * 1024) {
                throw new ExtractionFailed("The {$what} expands to more than Mnemon reads.");
            }
            $xml = strtr($xml, $entities);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $doc = new DOMDocument;
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $doc->documentElement === null) {
            throw new ExtractionFailed("The {$what} is not well-formed XML.");
        }

        return $doc;
    }
}
