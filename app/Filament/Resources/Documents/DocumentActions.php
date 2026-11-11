<?php

namespace App\Filament\Resources\Documents;

use App\Models\Document;
use App\Models\Wing;
use App\Services\Documents\DocumentIngestService;
use App\Services\Documents\DocumentRejected;
use App\Services\Documents\DocumentType;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The actions on uploaded documents, shared by the list and view pages.
 */
final class DocumentActions
{
    public const SOURCE = 'admin-upload';

    public static function upload(): Action
    {
        $maxMb = max(1, (int) config('mnemon.documents.max_mb', 25));

        return Action::make('upload')
            ->label('Upload documents')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Upload documents')
            ->modalDescription('Each file\'s text is extracted and stored as drawers; the original is kept for download. Images are described by the digest provider\'s vision model, one paid call per image.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                // Not stored by the form: the files go to DocumentIngestService,
                // which detects each type from its bytes and stores it itself.
                FileUpload::make('files')
                    ->label('Files')
                    ->multiple()
                    ->maxFiles(20)
                    ->maxSize($maxMb * 1024)
                    ->storeFiles(false)
                    ->previewable(false)
                    ->required()
                    ->helperText('Accepted: '.implode(', ', DocumentType::extensions())." · up to {$maxMb} MB each."),
                TextInput::make('wing')
                    ->required()
                    ->maxLength(100)
                    ->datalist(fn () => Wing::query()->orderBy('slug')->pluck('slug')->all())
                    ->helperText('A wing slug; created if it does not exist.'),
                TextInput::make('room')
                    ->default(fn () => (string) config('mnemon.documents.default_room', 'documents'))
                    ->maxLength(100),
                Textarea::make('note')
                    ->rows(3)
                    ->maxLength(5000)
                    ->helperText('Optional. Stored with each file and given to the vision model with an image.'),
                DateTimePicker::make('occurred_at')
                    ->label('Happened')
                    ->helperText('Optional. When the document is from, if not now.'),
            ])
            ->action(function (array $data): void {
                self::ingestAll($data);
            });
    }

    public static function download(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (Document $record) => self::downloadResponse($record));
    }

    public static function retry(): Action
    {
        return Action::make('retry')
            ->label('Extract again')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (Document $record) => 'The drawers this document made are replaced.'.(DocumentType::from($record->type)->isImage() ? ' An image is sent to the vision model again, a paid call.' : ''))
            ->visible(fn (Document $record) => $record->status !== Document::STATUS_PROCESSED || DocumentType::from($record->type)->isImage())
            ->action(function (Document $record): void {
                $doc = app(DocumentIngestService::class)->process($record);
                self::notifyOne($doc);
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Document $record) => "Delete {$record->original_name}?")
            ->modalDescription('Its drawers are deleted too (they can be restored from Drawers), and the stored file is removed.')
            ->action(function (Document $record, Action $action): void {
                app(DocumentIngestService::class)->delete($record);
                Notification::make()->title('Document deleted.')->success()->send();
                $action->redirect(DocumentResource::getUrl('index'));
            });
    }

    public static function deleteBulk(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete selected')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Their drawers are deleted too (they can be restored from Drawers), and the stored files are removed.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $service = app(DocumentIngestService::class);
                $records->each(fn (Document $doc) => $service->delete($doc));
                Notification::make()->title('Deleted '.$records->count().' document'.($records->count() === 1 ? '' : 's').'.')->success()->send();
            });
    }

    /**
     * Served as an attachment with its type pinned, never displayed inline:
     * an uploaded SVG or a mislabelled file is not rendered by the browser.
     */
    public static function downloadResponse(Document $document): StreamedResponse
    {
        return Storage::disk($document->disk)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ingestAll(array $data): void
    {
        $service = app(DocumentIngestService::class);
        $occurredAt = filled($data['occurred_at'] ?? null) ? Carbon::parse($data['occurred_at']) : null;

        $done = [];
        $problems = [];

        foreach (Arr::wrap($data['files'] ?? []) as $file) {
            // Only what Livewire itself received: a string here would be a
            // path the browser chose.
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $name = $file->getClientOriginalName();
            [$path, $temporary] = self::localPath($file);

            try {
                $doc = $service->ingest(
                    path: $path,
                    originalName: $name,
                    wingSlug: (string) $data['wing'],
                    roomSlug: $data['room'] ?? null,
                    source: self::SOURCE,
                    note: $data['note'] ?? null,
                    occurredAt: $occurredAt,
                )->document;

                if ($doc->status === Document::STATUS_PROCESSED) {
                    $done[] = "{$doc->original_name}: ".self::drawers($doc->chunk_count);
                } else {
                    $problems[] = "{$doc->original_name}: {$doc->error}";
                }
            } catch (DocumentRejected $e) {
                $problems[] = $e->getMessage();
            } finally {
                if ($temporary) {
                    @unlink($path);
                }
                $file->delete();
            }
        }

        $body = implode("\n", array_merge($done, $problems));
        $notification = Notification::make()->body(nl2br(e($body)));

        if ($problems === []) {
            $notification->title('Uploaded '.count($done).' file'.(count($done) === 1 ? '' : 's').'.')->success();
        } elseif ($done === []) {
            $notification->title('Nothing was extracted.')->danger()->persistent();
        } else {
            $notification->title('Uploaded with problems.')->warning()->persistent();
        }

        $notification->send();
    }

    private static function notifyOne(Document $doc): void
    {
        match ($doc->status) {
            Document::STATUS_PROCESSED => Notification::make()->title('Extracted: '.self::drawers($doc->chunk_count).'.')->success()->send(),
            default => Notification::make()->title('No text extracted')->body($doc->error)->warning()->persistent()->send(),
        };
    }

    private static function drawers(int $n): string
    {
        return $n.' drawer'.($n === 1 ? '' : 's');
    }

    /**
     * @return array{0: string, 1: bool} a readable path, and whether it is a copy to remove
     */
    private static function localPath(TemporaryUploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (is_string($path) && $path !== '' && is_file($path)) {
            return [$path, false];
        }

        // A temporary-upload disk that is not local (S3).
        $tmp = (string) tempnam(sys_get_temp_dir(), 'mnemon-upload-');
        file_put_contents($tmp, $file->readStream());

        return [$tmp, true];
    }
}
