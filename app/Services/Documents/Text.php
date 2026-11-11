<?php

namespace App\Services\Documents;

/**
 * Text taken out of a file, made fit for a drawer: valid UTF-8, Unix line
 * breaks, no control characters, no runs of blank lines.
 */
final class Text
{
    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        // Control characters other than tab and line feed, and the zero-width
        // and byte-order marks Office files scatter through their text.
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\x{200B}|\x{FEFF}/u', '', $text);
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** One line: every run of whitespace a single space. */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::clean($text)));
    }
}
