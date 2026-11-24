<?php

/** Every contract is bound in one file (FIDES "Inside a framework", containers
 *  point 1). Laravel would resolve an interface with a single implementation
 *  only if someone bound it somewhere, and that somewhere should be one place a
 *  reader can find: src/Delivery/Wiring.php. This check lists every interface
 *  in Entities, Features and Shared and fails when Wiring.php does not name it. */

/** @return list<string> */
function wiringProblems(string $srcRoot): array
{
    $wiringPath = "$srcRoot/".FIDES_WIRING_FILE;
    $wiring = is_file($wiringPath) ? (string) file_get_contents($wiringPath) : '';
    $named = is_file($wiringPath) ? referencedNames($wiring) : [];
    $problems = [];
    foreach (contractsIn($srcRoot) as $contract) {
        if (! in_array($contract, $named, true)) {
            $problems[] = "$contract is not bound in src/".FIDES_WIRING_FILE;
        }
    }

    return $problems;
}

/** Fully qualified names of the interfaces in the zones that declare contracts.
 *  @return list<string> */
function contractsIn(string $srcRoot): array
{
    $contracts = [];
    foreach (['Entities', 'Features', 'Shared'] as $zone) {
        if (! is_dir("$srcRoot/$zone")) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$srcRoot/$zone"));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/^interface\s+(\w+)/m', $code, $interface)
                && preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)) {
                $contracts[] = $namespace[1].'\\'.$interface[1];
            }
        }
    }
    sort($contracts);

    return $contracts;
}
