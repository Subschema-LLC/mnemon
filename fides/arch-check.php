<?php

/** The FIDES architecture check for Mnemon. Code in src/ is held to every rule
 *  this checker knows; code still in app/ is held to a ratchet (ratchet.php).
 *  It needs no Composer packages, so it runs before `composer install`.
 *
 *  Run it before you finish:  php fides/arch-check.php
 *  Lock in a lower app/ count: php fides/arch-check.php --update-ratchet
 *
 *  Exit 1 on any failure. Warnings (a file past 200 code lines) print but pass.
 *  Not checked yet: Blade views (rules 10 and 11) and settings (rule 9). */

require_once __DIR__.'/inheritance.php';
require_once __DIR__.'/source.php';
require_once __DIR__.'/zones.php';
require_once __DIR__.'/wiring.php';
require_once __DIR__.'/ratchet.php';

/** Parents and traits the framework requires of one file, with the reason.
 *  Laravel loads a service provider only if it extends ServiceProvider. */
const FIDES_EDGE = [FIDES_WIRING_FILE => ['ServiceProvider']];

/** @return array{failures: list<string>, warnings: list<string>} */
function checkSourceTree(string $srcRoot, string $repoRoot): array
{
    $failures = [];
    $warnings = [];
    $featureFiles = [];
    foreach (sourceFiles($srcRoot) as $rel) {
        $code = (string) file_get_contents("$srcRoot/$rel");
        $extension = str_ends_with($rel, '.blade.php')
            ? 'blade'
            : pathinfo($rel, PATHINFO_EXTENSION);
        $problems = [...pathProblems($rel), ...explanationProblems($code, $extension, $repoRoot)];
        if ($extension === 'php') {
            $problems = [
                ...$problems,
                ...inheritanceProblems($code, FIDES_EDGE[$rel] ?? []),
                ...namespaceAndImportProblems($rel, $code),
            ];
        }
        $size = sizeFindings($code, $extension === 'blade' ? 'html' : $extension);
        foreach ([...$problems, ...$size['failures']] as $problem) {
            $failures[] = "src/$rel: $problem";
        }
        foreach ($size['warnings'] as $warning) {
            $warnings[] = "src/$rel: $warning";
        }
        if (str_starts_with($rel, 'Features/')) {
            $featureFiles[] = $rel;
        }
    }

    return [
        'failures' => [
            ...$failures,
            ...featureGroupProblems($featureFiles),
            ...wiringProblems($srcRoot),
        ],
        'warnings' => $warnings,
    ];
}

/** Paths relative to src/ of every file the rules cover, sorted.
 *  @return list<string> */
function sourceFiles(string $srcRoot): array
{
    if (! is_dir($srcRoot)) {
        return [];
    }
    $paths = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
    foreach ($files as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'css', 'js'], true)) {
            $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($srcRoot) + 1));
        }
    }
    sort($paths);

    return $paths;
}

/** @param list<string> $arguments */
function runArchCheck(string $repoRoot, array $arguments): int
{
    $ratchetFile = __DIR__.'/ratchet.json';
    $current = legacyCounts("$repoRoot/app");
    if (in_array('--update-ratchet', $arguments, true)) {
        file_put_contents($ratchetFile, json_encode($current, JSON_PRETTY_PRINT)."\n");
        echo 'fides/ratchet.json updated: '.json_encode($current)."\n";
    }
    $recorded = json_decode((string) @file_get_contents($ratchetFile), true) ?: [];
    $source = checkSourceTree("$repoRoot/src", $repoRoot);
    $failures = [...$source['failures'], ...compareRatchet($recorded, $current)['failures']];
    foreach ($source['warnings'] as $warning) {
        echo "warning: $warning\n";
    }
    if ($failures !== []) {
        echo "arch-check FAILED:\n  - ".implode("\n  - ", $failures)."\n";

        return 1;
    }
    echo 'arch-check OK: src/ follows the FIDES rules; app/ '.json_encode($current)."\n";

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runArchCheck(dirname(__DIR__), array_slice($argv, 1)));
}
