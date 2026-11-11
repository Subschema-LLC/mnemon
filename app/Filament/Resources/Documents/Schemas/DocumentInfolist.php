<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Filament\Resources\Drawers\DrawerResource;
use App\Models\Document;
use App\Models\Drawer;
use App\Services\Documents\DocumentType;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('original_name')
                    ->label('File')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => DocumentsTable::statusColor($state))
                    ->formatStateUsing(fn (string $state) => DocumentsTable::statusLabel($state)),
                TextEntry::make('type')
                    ->formatStateUsing(fn (string $state) => DocumentType::tryFrom($state)?->label() ?? $state),
                TextEntry::make('error')
                    ->label(fn (Document $record) => $record->status === Document::STATUS_FAILED ? 'Error' : 'Why')
                    ->visible(fn (Document $record) => filled($record->error))
                    ->columnSpanFull(),
                TextEntry::make('room.wing.slug')
                    ->label('Wing')
                    ->placeholder('—'),
                TextEntry::make('room.slug')
                    ->label('Room')
                    ->placeholder('—'),
                TextEntry::make('size')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state, precision: 1)),
                TextEntry::make('chunk_count')
                    ->label('Drawers'),
                TextEntry::make('note')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('source'),
                TextEntry::make('occurred_at')
                    ->label('Happened')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Uploaded')
                    ->dateTime(),
                TextEntry::make('sha256')
                    ->label('SHA-256')
                    ->fontFamily('mono')
                    ->copyable(),
                // Values flattened to text: KeyValueEntry cannot show an array.
                KeyValueEntry::make('metadata')
                    ->label('Read from the file')
                    ->state(fn (Document $record) => collect($record->metadata ?? [])
                        ->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))
                        ->all())
                    ->visible(fn (Document $record) => ! empty($record->metadata))
                    ->columnSpanFull(),
                RepeatableEntry::make('drawers')
                    ->label('Drawers')
                    ->schema([
                        TextEntry::make('content')
                            ->hiddenLabel()
                            ->limit(400)
                            ->fontFamily('mono')
                            ->url(fn (Drawer $record) => DrawerResource::getUrl('view', ['record' => $record])),
                    ])
                    ->visible(fn (Document $record) => $record->chunk_count > 0)
                    ->columnSpanFull(),
            ]);
    }
}
