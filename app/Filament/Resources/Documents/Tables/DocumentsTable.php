<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Resources\Documents\DocumentActions;
use App\Models\Document;
use App\Models\Wing;
use App\Services\Documents\DocumentType;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('room.wing'))
            ->columns([
                TextColumn::make('original_name')
                    ->label('File')
                    ->searchable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => DocumentType::tryFrom($state)?->label() ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                    ->tooltip(fn (Document $record) => $record->error),
                TextColumn::make('room.wing.slug')
                    ->label('Wing')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('room.slug')
                    ->label('Room')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('chunk_count')
                    ->label('Drawers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('size')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state, precision: 1))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Document::STATUS_PROCESSED => self::statusLabel(Document::STATUS_PROCESSED),
                        Document::STATUS_NO_TEXT => self::statusLabel(Document::STATUS_NO_TEXT),
                        Document::STATUS_FAILED => self::statusLabel(Document::STATUS_FAILED),
                        Document::STATUS_PROCESSING => self::statusLabel(Document::STATUS_PROCESSING),
                    ]),
                SelectFilter::make('type')
                    ->options(collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $t) => [$t->value => $t->label()])->all()),
                SelectFilter::make('wing_id')
                    ->label('Wing')
                    ->options(fn () => Wing::query()->orderBy('slug')->pluck('slug', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('room', fn (Builder $q) => $q->where('wing_id', $data['value']))),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    DocumentActions::download(),
                    DocumentActions::retry(),
                    DocumentActions::delete(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DocumentActions::deleteBulk(),
                ]),
            ]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Document::STATUS_PROCESSED => 'Extracted',
            Document::STATUS_NO_TEXT => 'No text',
            Document::STATUS_FAILED => 'Failed',
            Document::STATUS_PROCESSING => 'Processing',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            Document::STATUS_PROCESSED => 'success',
            Document::STATUS_NO_TEXT => 'warning',
            Document::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }
}
