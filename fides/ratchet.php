<?php

/** The ratchet on code that has not moved to src/ yet (FIDES "Moving in a piece
 *  at a time"). Old code in app/ is not held to the rules, but its count of
 *  violations may go down, never up. fides/ratchet.json records the counts;
 *  a change that lowers one locks it in with --update-ratchet, so the count
 *  can never drift back up. A change that raises one fails, unless it updates
 *  the file too, which then shows in review.
 *
 *  What is counted, per rule:
 *  - inheritance: `extends` and traits (rule 6)
 *  - oversized_files: files past the hard size limit (rule 13)
 *  - facade_imports: Laravel facades, which hide a dependency the constructor
 *    should name ("Inside a framework", containers point 2) */

/** @return array<string, int> */
function legacyCounts(string $appRoot): array
{
    $counts = ['inheritance' => 0, 'oversized_files' => 0, 'facade_imports' => 0];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        $counts['inheritance'] += count(inheritanceProblems($code));
        $counts['oversized_files'] += codeLineCount($code, 'php') > FIDES_SIZE_MAX ? 1 : 0;
        $facades = '/^use\s+Illuminate\\\\Support\\\\Facades\\\\/m';
        $counts['facade_imports'] += preg_match_all($facades, $code);
    }

    return $counts;
}

/** @param array<string, int> $recorded
 *  @param array<string, int> $current
 *  @return array{failures: list<string>, lowered: bool} */
function compareRatchet(array $recorded, array $current): array
{
    $failures = [];
    $lowered = false;
    foreach ($current as $rule => $count) {
        $was = $recorded[$rule] ?? 0;
        if ($count > $was) {
            $failures[] = "app/ $rule went up from $was to $count: write new code in src/";
        } elseif ($count < $was) {
            $failures[] = "app/ $rule went down from $was to $count: "
                .'run php fides/arch-check.php --update-ratchet to lock it in';
            $lowered = true;
        }
    }

    return ['failures' => $failures, 'lowered' => $lowered];
}
