<?php

namespace App\Filament\Pages;

use App\Filament\Pages\LlmModels\LlmModelForm;
use App\Models\DigestUsage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Mnemon\Entities\LlmModel\InvalidLlmModel;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;

/**
 * The chat models Mnemon can run on, what they cost, and which one runs
 * digests, with the calls and spend recorded against each. The list is kept
 * in the settings file (LlmModel.yaml in the overrides folder): adding,
 * editing or deleting here rewrites that file, and a hand edit to the file
 * shows here on the next request.
 *
 * Spend is worked out from recorded tokens at today's price, so a price
 * edit re-prices history.
 */
class LlmModels extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $slug = 'llm-models';

    protected static ?string $title = 'LLM models';

    protected static ?string $navigationLabel = 'LLM models';

    protected string $view = 'filament.pages.llm-models';

    public function table(Table $table): Table
    {
        // Small totals keep four places: cents hide a trial's spend.
        $money = fn (float $cost) => '$'.number_format($cost, $cost < 1 ? 4 : 2);
        $price = fn (?float $state) => $state === null ? null : rtrim(rtrim(number_format($state, 6, '.', ''), '0'), '.');

        return $table
            ->records(fn (?string $search) => $this->rows($search))
            ->searchable()
            ->columns([
                TextColumn::make('provider'),
                TextColumn::make('model')->copyable(),
                IconColumn::make('use_for_digests')->label('Digests')->boolean(),
                TextColumn::make('price_in_per_mtok')->label('In $/1M')
                    ->formatStateUsing($price)->placeholder('—')->alignRight(),
                TextColumn::make('price_cached_in_per_mtok')->label('Cached $/1M')
                    ->formatStateUsing($price)->placeholder('—')->alignRight()->toggleable(),
                TextColumn::make('price_out_per_mtok')->label('Out $/1M')
                    ->formatStateUsing($price)->placeholder('—')->alignRight(),
                TextColumn::make('calls')->label('Calls')->numeric()->alignRight(),
                TextColumn::make('avg_in')->label('Avg in / call')->numeric()->placeholder('—')->alignRight(),
                TextColumn::make('avg_out')->label('Avg out / call')->numeric()->placeholder('—')->alignRight(),
                TextColumn::make('vision_calls')->label('Vision calls')
                    ->tooltip('Image captions for uploaded documents. Counted in spend, not in the per-call averages.')
                    ->numeric()->placeholder('—')->alignRight()->toggleable(),
                TextColumn::make('spend')->label('Spend')->placeholder('—')->alignRight()
                    ->state(fn (array $record) => match (true) {
                        $record['spend'] === null => null,
                        $record['spend'] === false => 'no price',
                        default => $money($record['spend']),
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (array $record) => "Edit {$record['model']}")
                    ->fillForm(fn (array $record) => LlmModelForm::fromModel($this->models()->findByKey($record['__key'])))
                    ->schema(fn (array $record) => LlmModelForm::components($record['__key']))
                    ->action(fn (array $record, array $data, Action $action) => $this->change(
                        $action,
                        fn (LlmModelList $models) => $models->put(LlmModelForm::toModel($data), $record['__key']),
                    )),
                Action::make('delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => "Delete {$record['model']}?")
                    ->modalDescription('Its recorded calls stay; they show again if the model is added back.')
                    ->action(fn (array $record, Action $action) => $this->change(
                        $action,
                        fn (LlmModelList $models) => $models->remove($record['__key']),
                    )),
            ])
            ->emptyStateHeading('No models yet')
            ->emptyStateDescription('Add a model to price its calls or to run digests on it.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New LLM model')
                ->modalHeading('New LLM model')
                ->schema(LlmModelForm::components())
                ->action(fn (array $data, Action $action) => $this->change(
                    $action,
                    fn (LlmModelList $models) => $models->put(LlmModelForm::toModel($data)),
                )),
        ];
    }

    /**
     * Applies a change to the list and saves it. A rule the form did not
     * catch is shown as it is, and nothing is saved.
     *
     * @param  callable(LlmModelList): LlmModelList  $change
     */
    private function change(Action $action, callable $change): void
    {
        $store = app(LlmModelStore::class);
        try {
            $store->save($change($store->load()));
        } catch (InvalidLlmModel $error) {
            Notification::make()->danger()->title('Not saved')->body($error->getMessage())->send();
            $action->halt();
        }
        $this->resetTable();
    }

    private function models(): LlmModelList
    {
        return app(LlmModelStore::class)->load();
    }

    /**
     * One row per model, with its usage worked out once for the whole table.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(?string $search): array
    {
        $digests = DigestUsage::totalsByModel(DigestUsage::PURPOSE_DIGEST);
        $everything = DigestUsage::totalsByModel();
        $rows = [];
        foreach ($this->models()->all() as $model) {
            if (filled($search) && ! Str::contains($model->key(), $search, ignoreCase: true)) {
                continue;
            }
            $rows[$model->key()] = $this->row($model, $digests[$model->key()] ?? null, $everything[$model->key()] ?? null);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(LlmModel $model, ?object $digests, ?object $everything): array
    {
        $calls = $digests->calls ?? 0;
        $cost = $everything === null ? null
            : $model->costOf($everything->prompt_tokens, $everything->cached_prompt_tokens, $everything->completion_tokens);

        return LlmModelForm::fromModel($model) + [
            '__key' => $model->key(),
            'calls' => $calls,
            'avg_in' => $calls ? intdiv($digests->prompt_tokens, $calls) : null,
            'avg_out' => $calls ? intdiv($digests->completion_tokens, $calls) : null,
            'vision_calls' => ($everything->calls ?? 0) - $calls ?: null,
            // null: never called; false: called, but a price is missing.
            'spend' => $everything === null ? null : ($cost ?? false),
        ];
    }
}
