<?php

namespace App\Filament\Pages\LlmModels;

use App\Services\Digest\DigestDrivers;
use App\Services\Digest\ModelCatalog;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelStore;

/**
 * The fields for adding or editing a chat model on the LLM models page. The
 * entity checks every rule again when the model is saved; these say what is
 * wrong beside the field instead of after saving.
 */
class LlmModelForm
{
    /**
     * @param  string|null  $editing  the key of the model being edited, so it
     *                                does not count as a duplicate of itself
     * @return array<int, mixed>
     */
    public static function components(?string $editing = null): array
    {
        $price = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->prefix('$')
            ->suffix('per 1M tokens');

        // Matched exactly against recorded usage, and a model id is sent
        // verbatim: one pasted space and every digest is a 4xx.
        $id = fn (string $name, string $label, int $max) => TextInput::make($name)
            ->label($label)
            ->required()
            ->maxLength($max)
            ->notRegex('/\s/')
            ->validationMessages(['not_regex' => 'An id has no spaces.']);

        return [
            Select::make('provider')
                ->required()
                ->default('openai')
                ->native(false)
                ->live()
                ->options(fn (?string $state) => ModelCatalog::providerOptions($state))
                ->rules(['max:'.LlmModel::PROVIDER_MAX_LENGTH, 'not_regex:/\s/'])
                ->afterStateUpdated(fn (Set $set) => $set('model', null))
                ->createOptionForm([
                    $id('provider', 'Provider id', LlmModel::PROVIDER_MAX_LENGTH)
                        ->placeholder('e.g. mistral')
                        ->helperText('Kept for price comparisons. Only the providers already listed can run digests.'),
                ])
                ->createOptionUsing(fn (array $data) => trim($data['provider']))
                ->createOptionModalHeading('Add a provider')
                ->helperText('OpenAI, Anthropic and OpenAI-compatible can run digests. Press + to add another provider for price comparison.'),
            Select::make('model')
                ->required()
                ->native(false)
                ->searchable()
                ->options(fn (Get $get, ?string $state) => ModelCatalog::modelOptions($get('provider'), $state))
                ->rules(['max:'.LlmModel::MODEL_MAX_LENGTH, 'not_regex:/\s/'])
                ->validationMessages(['not_regex' => 'A model id has no spaces.'])
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $editing) {
                    $key = "{$get('provider')} {$value}";
                    if ($key !== $editing && app(LlmModelStore::class)->load()->findByKey($key) !== null) {
                        $fail('This model is already listed for this provider.');
                    }
                })
                ->createOptionForm([
                    $id('model', 'Model id', LlmModel::MODEL_MAX_LENGTH)
                        ->placeholder('e.g. gpt-5.6-luna')
                        ->helperText('The exact model id the provider\'s API takes.'),
                ])
                ->createOptionUsing(fn (array $data) => trim($data['model']))
                ->createOptionModalHeading('Add a model that is not listed')
                ->helperText('Pick a model, or press + to enter any model id the provider\'s API takes. The list comes from the provider when the server has its key.'),
            $price('price_in_per_mtok', 'Input price'),
            $price('price_cached_in_per_mtok', 'Cached input price')
                ->helperText('Leave empty to charge cached input at the input price.'),
            $price('price_out_per_mtok', 'Output price')
                ->helperText('Output includes reasoning tokens.'),
            Toggle::make('use_for_digests')
                ->label('Use for digests')
                ->helperText(function (Get $get) {
                    $text = 'Session digests and backfills run on this model, through its provider. Marking it unmarks any other. With none marked, the server\'s configured driver and model are used.';
                    $missing = DigestDrivers::missingSetting((string) $get('provider'));

                    return $missing === null ? $text : "{$text} {$missing} is not set on the server, so digests on this model will fail until it is.";
                })
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                    if ($value && ! LlmModel::canDigestWith((string) $get('provider'))) {
                        $fail('Only an OpenAI, Anthropic or OpenAI-compatible model can be used for digests.');
                    }
                }),
            Textarea::make('notes')
                ->rows(2)
                ->helperText('Where the prices came from, and when.'),
        ];
    }

    /**
     * A model from the form's values. Throws InvalidLlmModel if a rule the
     * fields did not catch is broken.
     *
     * @param  array<string, mixed>  $data
     */
    public static function toModel(array $data): LlmModel
    {
        $price = fn (string $key) => ($data[$key] ?? '') === '' || $data[$key] === null
            ? null
            : (float) $data[$key];
        $notes = trim((string) ($data['notes'] ?? ''));

        return new LlmModel(
            trim((string) $data['provider']),
            trim((string) $data['model']),
            $price('price_in_per_mtok'),
            $price('price_cached_in_per_mtok'),
            $price('price_out_per_mtok'),
            (bool) ($data['use_for_digests'] ?? false),
            $notes === '' ? null : $notes,
        );
    }

    /**
     * The form's values for a model being edited.
     *
     * @return array<string, mixed>
     */
    public static function fromModel(LlmModel $model): array
    {
        return [
            'provider' => $model->provider,
            'model' => $model->model,
            'price_in_per_mtok' => $model->priceInPerMtok,
            'price_cached_in_per_mtok' => $model->priceCachedInPerMtok,
            'price_out_per_mtok' => $model->priceOutPerMtok,
            'use_for_digests' => $model->usedForDigests,
            'notes' => $model->notes,
        ];
    }
}
