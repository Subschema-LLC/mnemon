<?php

namespace App\Services\Documents\Extractors;

use App\Models\Document;
use App\Services\Documents\Extraction;
use App\Services\Documents\ExtractionFailed;
use App\Services\Documents\Extractor;
use App\Services\Documents\Section;
use App\Services\Documents\Text;

/**
 * A CSV file's rows, kept as CSV, in drawer-sized runs that each start with
 * the header row -- so every drawer can be read on its own, and a search for
 * a value finds the row with its column names beside it.
 *
 * The delimiter is the one of , ; tab | that the first line uses most, and a
 * file that is not UTF-8 is read as Windows-1252, the encoding spreadsheet
 * programs most often export.
 */
class CsvExtractor implements Extractor
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function extract(string $path, Document $document): Extraction
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ExtractionFailed('The CSV file could not be read.');
        }

        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $delimiter = $this->delimiter(strtok($raw, "\n") ?: '');
        $rows = $this->rows($raw, $delimiter);
        if ($rows === []) {
            return new Extraction([], reason: 'The CSV file holds no rows.');
        }

        $header = array_shift($rows);
        $headerLine = $this->line($header, $delimiter);
        $budget = max(500, (int) config('mnemon.documents.chunk_chars', 4000));

        $sections = [];
        $lines = [];
        $length = mb_strlen($headerLine);
        $first = 1;

        foreach ($rows as $i => $row) {
            $line = $this->line($row, $delimiter);
            $lineLength = mb_strlen($line) + 1;

            if ($lines !== [] && $length + $lineLength > $budget) {
                $sections[] = $this->section($headerLine, $lines, $first, $i, count($rows));
                $lines = [];
                $length = mb_strlen($headerLine);
                $first = $i + 1;
            }

            $lines[] = $line;
            $length += $lineLength;
        }

        if ($lines !== []) {
            $sections[] = $this->section($headerLine, $lines, $first, count($rows), count($rows));
        }

        // A header with no data rows is still something someone may search for.
        if ($sections === []) {
            $sections[] = new Section('header', Text::clean($headerLine));
        }

        return new Extraction(
            $sections,
            metadata: ['rows' => count($rows), 'columns' => count($header), 'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter],
            mergeable: false,
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function section(string $header, array $lines, int $from, int $to, int $total): Section
    {
        // Data rows are numbered from 1, the header not counted.
        $label = $from === $to ? "row {$from} of {$total}" : "rows {$from}–{$to} of {$total}";

        return new Section($label, Text::clean($header."\n".implode("\n", $lines)), 'rows', $from);
    }

    private function delimiter(string $firstLine): string
    {
        // Quoted fields can hold delimiters; count only what is outside quotes.
        $unquoted = (string) preg_replace('/"[^"]*"/', '', $firstLine);
        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$candidate, $count];
            }
        }

        return $best;
    }

    /**
     * Parsed records, so a quoted field spanning lines stays one row.
     *
     * @return array<int, array<int, string>>
     */
    private function rows(string $raw, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                continue;
            }
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function line(array $cells, string $delimiter): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $cells, $delimiter, '"', '');
        rewind($stream);
        $line = rtrim((string) stream_get_contents($stream), "\r\n");
        fclose($stream);

        return $line;
    }
}
