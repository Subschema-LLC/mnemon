<?php

namespace App\Services\Documents;

use ZipArchive;

/**
 * The file types Mnemon reads, decided by content as well as by name.
 *
 * The extension picks the candidate and the first bytes must agree with it:
 * a .pdf that is really an HTML page, or a .png that is really a zip, is
 * refused rather than handed to a parser that does not expect it.
 */
enum DocumentType: string
{
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Png = 'png';
    case Jpg = 'jpg';
    case Webp = 'webp';
    case Svg = 'svg';

    /** @return array<int, string> Extensions accepted, for upload forms and messages. */
    public static function extensions(): array
    {
        return ['docx', 'pptx', 'pdf', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'svg'];
    }

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower($extension);

        return self::tryFrom($extension === 'jpeg' ? 'jpg' : $extension);
    }

    public function mime(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pptx => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            self::Pdf => 'application/pdf',
            self::Csv => 'text/csv',
            self::Png => 'image/png',
            self::Jpg => 'image/jpeg',
            self::Webp => 'image/webp',
            self::Svg => 'image/svg+xml',
        };
    }

    /** Raster images, described by a vision model rather than read. */
    public function isImage(): bool
    {
        return in_array($this, [self::Png, self::Jpg, self::Webp], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Docx => 'Word document',
            self::Pptx => 'PowerPoint presentation',
            self::Pdf => 'PDF',
            self::Csv => 'CSV file',
            self::Png => 'PNG image',
            self::Jpg => 'JPEG image',
            self::Webp => 'WebP image',
            self::Svg => 'SVG image',
        };
    }

    /** The label with its article, for a sentence. */
    public function withArticle(): string
    {
        return ($this === self::Svg ? 'an ' : 'a ').$this->label();
    }

    /**
     * @throws DocumentRejected when the type is not one Mnemon reads, or the content is not what the name says
     */
    public static function detect(string $path, string $originalName): self
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $type = self::fromExtension($extension);

        if ($type === null) {
            $accepted = implode(', ', array_diff(self::extensions(), ['jpeg']));

            throw new DocumentRejected($extension === ''
                ? "The file has no extension; Mnemon reads {$accepted}."
                : "Mnemon does not read .{$extension} files; it reads {$accepted}.");
        }

        $head = (string) @file_get_contents($path, false, null, 0, 8192);

        $matches = match ($type) {
            self::Pdf => str_contains(substr($head, 0, 1024), '%PDF-'),
            self::Png => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            self::Jpg => str_starts_with($head, "\xFF\xD8\xFF"),
            self::Webp => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            self::Docx => self::zipHolds($path, 'word/document.xml'),
            self::Pptx => self::zipHolds($path, 'ppt/presentation.xml'),
            self::Svg => ! str_contains($head, "\0") && preg_match('/<svg[\s>]/i', $head) === 1,
            self::Csv => ! str_contains($head, "\0"),
        };

        if (! $matches) {
            throw new DocumentRejected("The file is named .{$extension}, but its content is not {$type->withArticle()}.");
        }

        return $type;
    }

    private static function zipHolds(string $path, string $entry): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4);
        if ($head !== "PK\x03\x04") {
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $found = $zip->locateName($entry) !== false;
        $zip->close();

        return $found;
    }
}
