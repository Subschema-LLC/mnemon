<?php

/** The inheritance part of arch-check.php: `extends` and traits (FIDES rule 6).
 *  Copied from the FIDES Symfony example. It reads PHP tokens, so comments and
 *  strings never count.
 *
 *  - `extends` is allowed only when the parent is a built-in exception,
 *    because PHP only throws Throwables.
 *  - A trait used in a class, trait or enum body counts as inheritance. A
 *    closure's `use ($x)` sits inside a method, deeper than the body, and is
 *    not a trait.
 *  - $allowed names the parents and traits a framework requires of this one
 *    file, listed in arch-check.php with the reason. */

/**
 * @param  list<string>  $allowed
 * @return list<string>
 */
function inheritanceProblems(string $code, array $allowed = []): array
{
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn ($token): bool => ! is_array($token) || ! in_array($token[0], $skip, true)
    ));
    $problems = [];
    $depth = 0;
    $bodies = [];
    $bodyNext = false;
    foreach ($tokens as $i => $token) {
        $id = is_array($token) ? $token[0] : $token;
        $afterColons = is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON;
        if (in_array($id, [T_CLASS, T_TRAIT, T_ENUM], true) && ! $afterColons) {
            $bodyNext = true;
        } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            if ($bodyNext) {
                $bodies[] = $depth;
                $bodyNext = false;
            }
        } elseif ($id === '}') {
            if (end($bodies) === $depth) {
                array_pop($bodies);
            }
            $depth--;
        } elseif ($id === T_EXTENDS) {
            foreach (namesAfter($tokens, $i) as $parent) {
                if (! isBuiltInException($parent) && ! in_array($parent, $allowed, true)) {
                    $problems[] = "extends $parent (no inheritance)";
                }
            }
        } elseif ($id === T_USE && end($bodies) === $depth) {
            foreach (array_diff(namesAfter($tokens, $i), $allowed) as $trait) {
                $problems[] = "uses trait $trait (traits count as inheritance)";
            }
        }
    }

    return $problems;
}

/** The class names right after token $i, separated by commas.
 *  @param list<mixed> $tokens
 *  @return list<string> */
function namesAfter(array $tokens, int $i): array
{
    $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    $names = [];
    for ($j = $i + 1; isset($tokens[$j]); $j++) {
        $token = $tokens[$j];
        if (is_array($token) && in_array($token[0], $nameTokens, true)) {
            $names[] = ltrim($token[1], '\\');
        } elseif ($token !== ',') {
            break;
        }
    }

    return $names;
}

function isBuiltInException(string $name): bool
{
    return class_exists($name, false)
        && (new ReflectionClass($name))->isInternal()
        && is_a($name, Throwable::class, true);
}
