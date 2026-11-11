<?php

namespace App\Services\Documents;

use Illuminate\Support\Str;

/**
 * Sections sized to drawers.
 *
 * A section longer than the limit is split, at a paragraph break if it can be,
 * then a line break, a sentence, a word, and only last mid-word. Neighbouring
 * sections that fit together are merged, each still marked with its label, so
 * a deck of short slides does not become a drawer per slide.
 */
final class Chunker
{
    /**
     * @param  array<int, Section>  $sections
     * @return array<int, Section>
     */
    public function chunk(array $sections, int $max, bool $mergeable = true): array
    {
        $max = max(200, $max);

        $pieces = [];
        foreach ($sections as $section) {
            array_push($pieces, ...$this->split($section, $max));
        }

        if (! $mergeable || count($pieces) < 2) {
            return $pieces;
        }

        $chunks = [];
        $group = [];
        $length = 0;
        foreach ($pieces as $piece) {
            // Measured as it would be written: "[label]\n" before each merged piece.
            $add = mb_strlen($piece->text) + mb_strlen($piece->label) + 4;
            if ($group !== [] && $length + $add > $max) {
                $chunks[] = $this->merge($group);
                $group = [];
                $length = 0;
            }
            $group[] = $piece;
            $length += $add;
        }
        $chunks[] = $this->merge($group);

        return $chunks;
    }

    /** @return array<int, Section> */
    private function split(Section $section, int $max): array
    {
        if (mb_strlen($section->text) <= $max) {
            return [$section];
        }

        $parts = $this->pieces($section->text, $max, ["\n\n", "\n", '. ', ' ']);
        $total = count($parts);

        return array_map(
            fn (string $text, int $i) => new Section("{$section->label} (part ".($i + 1)." of {$total})", $text, $section->kind, $section->number),
            $parts,
            array_keys($parts),
        );
    }

    /**
     * @param  array<int, string>  $separators
     * @return array<int, string>
     */
    private function pieces(string $text, int $max, array $separators): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        if ($separators === []) {
            $out = [];
            for ($i = 0, $n = mb_strlen($text); $i < $n; $i += $max) {
                $out[] = mb_substr($text, $i, $max);
            }

            return $out;
        }

        $separator = array_shift($separators);
        $parts = explode($separator, $text);
        $last = count($parts) - 1;

        $out = [];
        $current = '';
        foreach ($parts as $i => $part) {
            $piece = $i < $last ? $part.$separator : $part;

            if (mb_strlen($piece) > $max) {
                if (trim($current) !== '') {
                    $out[] = $current;
                }
                $current = '';
                array_push($out, ...$this->pieces($piece, $max, $separators));

                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > $max) {
                $out[] = $current;
                $current = '';
            }
            $current .= $piece;
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }

        return array_values(array_filter(array_map('trim', $out), fn ($p) => $p !== ''));
    }

    /**
     * @param  array<int, Section>  $group
     */
    private function merge(array $group): Section
    {
        if (count($group) === 1) {
            return $group[0];
        }

        $first = $group[0];
        $last = $group[count($group) - 1];

        $text = implode("\n\n", array_map(fn (Section $s) => "[{$s->label}]\n{$s->text}", $group));

        $sameKind = $first->kind !== null && collect($group)->every(fn (Section $s) => $s->kind === $first->kind && $s->number !== null);
        if ($sameKind && $first->kind !== 'section') {
            $label = $first->number === $last->number
                ? $first->label
                : Str::plural($first->kind)." {$first->number}–{$last->number}";
        } else {
            $label = "{$first->label} – {$last->label}";
        }

        return new Section($label, $text, $first->kind, $first->number);
    }
}
