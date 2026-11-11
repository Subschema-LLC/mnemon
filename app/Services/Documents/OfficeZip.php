<?php

namespace App\Services\Documents;

use DOMDocument;
use ZipArchive;

/**
 * The parts of a .docx or .pptx, read with a ceiling.
 *
 * An Office file is a zip, and a zip can claim to hold far more than it is
 * (a zip bomb). Each part is read with a length limit, so a lying header
 * cannot make it larger, and the parts read from one file share a budget.
 */
final class OfficeZip
{
    /** Largest single part read. */
    public const MAX_PART_BYTES = 64 * 1024 * 1024;

    /** Most read from one file, all parts together. */
    public const MAX_TOTAL_BYTES = 256 * 1024 * 1024;

    private ZipArchive $zip;

    private int $read = 0;

    public function __construct(string $path, private readonly string $what)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ExtractionFailed("The {$what} could not be opened; it may be damaged.");
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    public function has(string $name): bool
    {
        return $this->zip->locateName($name) !== false;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function read(string $name): ?string
    {
        if (! $this->has($name)) {
            return null;
        }

        $stat = $this->zip->statName($name);
        if ($stat !== false && $stat['size'] > self::MAX_PART_BYTES) {
            throw new ExtractionFailed("The {$this->what} has a part larger than Mnemon reads ({$name}).");
        }

        $bytes = $this->zip->getFromName($name, self::MAX_PART_BYTES + 1);
        if ($bytes === false) {
            throw new ExtractionFailed("The {$this->what} could not be read; it may be damaged ({$name}).");
        }

        $this->read += strlen($bytes);
        if (strlen($bytes) > self::MAX_PART_BYTES || $this->read > self::MAX_TOTAL_BYTES) {
            throw new ExtractionFailed("The {$this->what} expands to more than Mnemon reads.");
        }

        return $bytes;
    }

    public function xml(string $name): ?DOMDocument
    {
        $bytes = $this->read($name);

        return $bytes === null ? null : SafeXml::load($bytes, $this->what);
    }

    /**
     * The targets of a part's relationships, by id, resolved to paths inside
     * the zip.
     *
     * @return array<string, array{target: string, type: string}>
     */
    public function relationships(string $part): array
    {
        $dir = dirname($part);
        $rels = $this->xml(($dir === '.' ? '' : $dir.'/').'_rels/'.basename($part).'.rels');
        if ($rels === null) {
            return [];
        }

        $out = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $out[$rel->getAttribute('Id')] = [
                'target' => self::resolve($dir, $rel->getAttribute('Target')),
                'type' => $rel->getAttribute('Type'),
            ];
        }

        return $out;
    }

    /** A relationship target, relative to its part's directory, as a zip path. */
    public static function resolve(string $dir, string $target): string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : ($dir === '.' ? '' : $dir.'/').$target;

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '' && $segment !== '.') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Title, author and dates from docProps/core.xml, when they are set.
     *
     * @return array<string, string>
     */
    public function coreProperties(): array
    {
        $core = $this->has('docProps/core.xml') ? $this->xml('docProps/core.xml') : null;
        if ($core === null) {
            return [];
        }

        $out = [];
        foreach (['title' => 'title', 'creator' => 'author', 'created' => 'created', 'modified' => 'modified'] as $tag => $key) {
            $node = $core->getElementsByTagNameNS('*', $tag)->item(0);
            $value = $node === null ? '' : Text::line($node->textContent);
            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, 255);
            }
        }

        return $out;
    }
}
