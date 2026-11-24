<?php

/** Where a file in src/ sits and what it may import (FIDES rules 2 to 5 and 12).
 *
 *  - Paths are PascalCase, at most zone/owner/file deep; features and delivery
 *    allow one more level (feature groups, delivery/<door>/<page>/file).
 *  - The namespace is Mnemon\ plus the folder path.
 *  - Entities import only their own folder; features import entities, shared
 *    and their own folder; shared imports nothing of the app's.
 *  - Integrations import entities, shared and their own folder, and may wrap
 *    old code in app/ (App\) as an adapter until it moves.
 *  - Delivery imports features, shared and delivery. Only the wiring file
 *    names integrations, and it may name the entity contracts it binds.
 *  - The core (entities, features, shared) imports nothing from a vendor
 *    except what FIDES_CORE_VENDOR_ALLOWED lists, each with its reason.
 *  - Nothing in src/ imports old code, except integrations. */
const FIDES_PREFIX = 'Mnemon';
const FIDES_ZONES = ['Entities', 'Features', 'Shared', 'Integrations', 'Delivery'];
const FIDES_WIRING_FILE = 'Delivery/Wiring.php';
/** Plain vendor utilities the core may use. Empty: add one with its reason. */
const FIDES_CORE_VENDOR_ALLOWED = [];

/** @return list<string> */
function pathProblems(string $rel): array
{
    $parts = explode('/', $rel);
    $problems = [];
    if (! in_array($parts[0], FIDES_ZONES, true)) {
        return ["'{$parts[0]}' is not a zone (".implode(', ', FIDES_ZONES).')'];
    }
    $maxDepth = in_array($parts[0], ['Features', 'Delivery'], true) ? 4 : 3;
    if (count($parts) > $maxDepth) {
        $problems[] = "deeper than $maxDepth levels";
    }
    foreach (array_slice($parts, 0, -1) as $folder) {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $folder)) {
            $problems[] = "folder '$folder' must be PascalCase";
        }
    }
    if (! preg_match('/^[A-Z][A-Za-z0-9]*(\.blade)?\.(php|css|js)$/', end($parts))) {
        $problems[] = 'file name must be PascalCase';
    }

    return $problems;
}

/** Feature groups are all or none, and a group folder holds no code.
 *  @param list<string> $featureFiles paths relative to src/
 *  @return list<string> */
function featureGroupProblems(array $featureFiles): array
{
    $depths = array_unique(array_map(static fn ($rel) => substr_count($rel, '/'), $featureFiles));

    return count($depths) > 1
        ? ['Features/ mixes grouped and ungrouped features: use groups everywhere or nowhere']
        : [];
}

/** @return list<string> */
function namespaceAndImportProblems(string $rel, string $code): array
{
    $parts = explode('/', $rel);
    $folder = implode('\\', array_slice($parts, 0, -1));
    $problems = [];
    if (preg_match('/^namespace\s+([^;]+);/m', $code, $match)
        && $match[1] !== FIDES_PREFIX.'\\'.$folder) {
        $problems[] = "namespace {$match[1]} must match its folder (".FIDES_PREFIX."\\$folder)";
    }
    foreach (referencedNames($code) as $name) {
        if (! mayReference($rel, $name)) {
            $problems[] = "may not import $name";
        }
    }

    return array_values(array_unique($problems));
}

/** Names from `use` statements (grouped ones expanded) and fully qualified
 *  names in the code. Trait `use` inside a class body is the inheritance
 *  check's job; a top-level name is what matters here.
 *
 *  @return list<string> */
function referencedNames(string $code): array
{
    $names = [];
    // use [function|const] Name[\{Member, Member as Alias}] [as Alias];
    $pattern = '/^use\s+(?:function\s+|const\s+)?([A-Za-z0-9_\\\\]+?)'
        .'(?:\\\\\{([^}]*)\})?(?:\s+as\s+\w+)?;/ms';
    preg_match_all($pattern, $code, $uses, PREG_SET_ORDER);
    foreach ($uses as $use) {
        if (($use[2] ?? '') === '') {
            $names[] = $use[1];

            continue;
        }
        foreach (explode(',', $use[2]) as $member) {
            $member = trim((string) preg_replace('/\s+as\s+\w+$/', '', trim($member)));
            if ($member !== '') {
                $names[] = $use[1].'\\'.$member;
            }
        }
    }
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return array_values(array_unique($names));
}

function mayReference(string $rel, string $name): bool
{
    $parts = explode('/', $rel);
    $zone = $parts[0];
    $ownFolder = implode('\\', array_slice($parts, 0, -1));
    $target = explode('\\', $name);
    if (count($target) === 1) {
        return true; // a global PHP class or function: DateTimeImmutable, sprintf
    }
    if ($target[0] === 'App') {
        return $zone === 'Integrations';
    }
    if ($target[0] !== FIDES_PREFIX) {
        $isCore = in_array($zone, ['Entities', 'Features', 'Shared'], true);

        return ! $isCore || in_array($name, FIDES_CORE_VENDOR_ALLOWED, true);
    }
    $targetZone = $target[1] ?? '';
    $targetPath = implode('\\', array_slice($target, 1)).'\\';
    $inOwnFolder = str_starts_with($targetPath, $ownFolder.'\\');

    return match ($zone) {
        'Entities' => $inOwnFolder,
        'Features' => in_array($targetZone, ['Entities', 'Shared'], true) || $inOwnFolder,
        'Shared' => $inOwnFolder,
        'Integrations' => in_array($targetZone, ['Entities', 'Shared'], true) || $inOwnFolder,
        'Delivery' => in_array($targetZone, ['Features', 'Shared', 'Delivery'], true)
            || ($rel === FIDES_WIRING_FILE
                && in_array($targetZone, ['Entities', 'Integrations'], true)),
        default => false,
    };
}
