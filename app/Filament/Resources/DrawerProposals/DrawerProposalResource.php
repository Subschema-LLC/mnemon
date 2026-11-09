<?php

namespace App\Filament\Resources\DrawerProposals;

use App\Filament\Resources\DrawerProposals\Pages\ListDrawerProposals;
use App\Models\DrawerProposal;
use App\Models\Wing;
use App\Services\DrawerProposalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * The digest review queue: what session_digest held back for the owner
 * (MNEMON_DIGEST_REVIEW). Approve files a proposal as a drawer, Edit and
 * Approve lets the owner change its text, wing or room first, and Discard
 * turns it away. Decided proposals stay, filterable, as a record.
 */
class DrawerProposalResource extends Resource
{
    protected static ?string $model = DrawerProposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Review Queue';

    protected static ?string $modelLabel = 'Proposal';

    protected static ?string $pluralModelLabel = 'Review Queue';

    public static function getNavigationBadge(): ?string
    {
        $pending = DrawerProposal::where('status', DrawerProposal::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('content')
                    ->limit(240)
                    ->wrap()
                    ->grow()
                    ->searchable(),
                TextColumn::make('wing_slug')
                    ->label('Wing')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('room_slug')
                    ->label('Room')
                    ->description(fn (DrawerProposal $record): ?string => $record->propose_new_room ? 'new room' : null)
                    ->searchable(),
                TextColumn::make('confidence')
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        DrawerProposal::REASON_BELOW_FLOOR => 'Below floor',
                        DrawerProposal::REASON_REVIEW_ALL => 'Review all',
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === DrawerProposal::REASON_BELOW_FLOOR ? 'warning' : 'gray'),
                TextColumn::make('status')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DrawerProposal::STATUS_PENDING => 'warning',
                        DrawerProposal::STATUS_APPROVED => 'success',
                        DrawerProposal::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('session_id')
                    ->label('Session')
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Proposed')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        DrawerProposal::STATUS_PENDING => 'Pending',
                        DrawerProposal::STATUS_APPROVED => 'Approved',
                        DrawerProposal::STATUS_REJECTED => 'Discarded',
                    ])
                    ->default(DrawerProposal::STATUS_PENDING),
                SelectFilter::make('reason')
                    ->options([
                        DrawerProposal::REASON_BELOW_FLOOR => 'Below floor',
                        DrawerProposal::REASON_REVIEW_ALL => 'Review all',
                    ]),
                SelectFilter::make('wing_slug')
                    ->label('Wing')
                    ->options(fn () => DrawerProposal::query()->distinct()->orderBy('wing_slug')->pluck('wing_slug', 'wing_slug')->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->visible(fn (DrawerProposal $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->action(fn (DrawerProposal $record) => self::approve($record)),
                ActionGroup::make([
                    Action::make('edit')
                        ->label('Edit and Approve')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (DrawerProposal $record): bool => $record->isPending())
                        ->fillForm(fn (DrawerProposal $record): array => [
                            'content' => $record->content,
                            'wing_slug' => $record->wing_slug,
                            'room_slug' => $record->room_slug,
                        ])
                        ->schema([
                            Textarea::make('content')
                                ->required()
                                ->rows(10),
                            Select::make('wing_slug')
                                ->label('Wing')
                                ->options(fn () => Wing::query()->orderBy('name')->pluck('name', 'slug')->all())
                                ->searchable()
                                ->required(),
                            TextInput::make('room_slug')
                                ->label('Room')
                                ->helperText('An existing room of that wing, or a new one to create.')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->action(fn (DrawerProposal $record, array $data) => self::approve($record, $data['content'], $data['wing_slug'], $data['room_slug'])),
                    Action::make('discard')
                        ->label('Discard')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible(fn (DrawerProposal $record): bool => $record->isPending())
                        ->requiresConfirmation()
                        ->action(fn (DrawerProposal $record) => app(DrawerProposalService::class)->reject($record, Auth::user())),
                    Action::make('view_drawer')
                        ->label('Open Drawer')
                        ->visible(fn (DrawerProposal $record): bool => $record->drawer_id !== null)
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (DrawerProposal $record): string => route('filament.admin.resources.drawers.view', ['record' => $record->drawer_id])),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Approve selected')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $failed = [];
                            foreach ($records as $record) {
                                if (! $record->isPending()) {
                                    continue;
                                }
                                try {
                                    app(DrawerProposalService::class)->approve($record, Auth::user());
                                    $approved++;
                                } catch (InvalidArgumentException $e) {
                                    $failed[] = "#{$record->id}: {$e->getMessage()}";
                                }
                            }
                            $note = Notification::make()->title("Approved {$approved} proposal".($approved === 1 ? '' : 's').'.');
                            ($failed === [] ? $note->success() : $note->warning()->body(implode("\n", $failed)))->send();
                        }),
                    BulkAction::make('discard_selected')
                        ->label('Discard selected')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                app(DrawerProposalService::class)->reject($record, Auth::user());
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrawerProposals::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function approve(DrawerProposal $record, ?string $content = null, ?string $wingSlug = null, ?string $roomSlug = null): void
    {
        try {
            app(DrawerProposalService::class)->approve($record, Auth::user(), $content, $wingSlug, $roomSlug);
            Notification::make()->title('Filed as a drawer.')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Not approved')->body($e->getMessage())->danger()->send();
        }
    }
}
