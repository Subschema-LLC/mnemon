<?php

/** Checks on one file's text, whatever zone it sits in: size counted in code
 *  lines (rule 13), line length (rule 12), and the explanation rules (rule 14):
 *  a header, a TODO(#123) that names its issue, and a `Decision: <name>` with a
 *  record in docs/decisions/. */
const FIDES_SIZE_AIM = 200;
const FIDES_SIZE_MAX = 300;
const FIDES_LINE_MAX = 100;

/** Lines holding code. Blank lines, comments and docblocks do not count, so
 *  explaining a file never pushes it over the limit. */
function codeLineCount(string $code, string $extension): int
{
    if ($extension !== 'php') {
        $stripped = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $lines = array_filter(
            explode("\n", $stripped),
            static fn (string $line): bool => ! preg_match('#^\s*(//.*)?$#', $line)
        );

        return count($lines);
    }
    $codeLines = [];
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML];
    $line = 1;
    foreach (token_get_all($code) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $line = is_array($token) ? $token[2] : $line;
        if (! is_array($token) || ! in_array($token[0], $skip, true)) {
            foreach (range($line, $line + substr_count($text, "\n")) as $covered) {
                $codeLines[$covered] = true;
            }
        }
        $line += substr_count($text, "\n");
    }

    return count($codeLines);
}

/** @return array{failures: list<string>, warnings: list<string>} */
function sizeFindings(string $code, string $extension): array
{
    $count = codeLineCount($code, $extension);
    if ($count > FIDES_SIZE_MAX) {
        return ['failures' => ["$count code lines (max ".FIDES_SIZE_MAX.')'],
            'warnings' => []];
    }
    if ($count > FIDES_SIZE_AIM) {
        return ['failures' => [], 'warnings' => [
            "$count code lines (aim ".FIDES_SIZE_AIM.'): split out any concept you can name; '
            .'keep it whole only if one name covers all of it, and say why in the change',
        ]];
    }

    return ['failures' => [], 'warnings' => []];
}

/** @return list<string> */
function explanationProblems(string $code, string $extension, string $repoRoot): array
{
    $problems = [];
    if (! opensWithHeader($code, $extension)) {
        $problems[] = 'must open with a comment saying what it does and why';
    }
    $comments = commentText($code, $extension);
    if (preg_match_all('/\bTODO\b(?!\(#\d+\))/', $comments) > 0) {
        $problems[] = 'a TODO must name its issue: TODO(#123)';
    }
    preg_match_all('/Decision: ([A-Za-z0-9_-]+)/', $comments, $decisions);
    foreach (array_unique($decisions[1]) as $name) {
        if (! is_file("$repoRoot/docs/decisions/$name.md")) {
            $problems[] = "names Decision: $name, but docs/decisions/$name.md does not exist";
        }
    }
    foreach (explode("\n", $code) as $number => $text) {
        if (mb_strlen($text) > FIDES_LINE_MAX) {
            $problems[] = 'line '.($number + 1).' is longer than '.FIDES_LINE_MAX
                .' characters';
        }
    }

    return $problems;
}

/** The comments in a PHP file, so a to-do marker or a decision name in a string
 *  does not count. Other files are searched whole. */
function commentText(string $code, string $extension): string
{
    if ($extension !== 'php') {
        return $code;
    }
    $comments = array_filter(
        token_get_all($code),
        static fn ($token): bool => is_array($token)
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
    );

    return implode("\n", array_map(static fn (array $token): string => $token[1], $comments));
}

/** PHP: the first thing after `<?php` (and an optional declare) is a comment.
 *  CSS and JS: the file starts with a comment. Blade: it starts with {{-- --}}. */
function opensWithHeader(string $code, string $extension): bool
{
    if ($extension === 'blade') {
        return str_starts_with(ltrim($code), '{{--');
    }
    if ($extension !== 'php') {
        return (bool) preg_match('#^\s*(/\*|//)#', $code);
    }
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn ($token): bool => ! is_array($token) || $token[0] !== T_WHITESPACE
    ));
    $i = 1;
    if (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_DECLARE) {
        while (isset($tokens[$i]) && $tokens[$i] !== ';') {
            $i++;
        }
        $i++;
    }

    return is_array($tokens[$i] ?? null)
        && in_array($tokens[$i][0], [T_DOC_COMMENT, T_COMMENT], true);
}
