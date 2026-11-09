<?php

namespace App\Filament\Resources\LlmModels\Schemas;

use App\Models\LlmModel;
use App\Services\Digest\DigestDrivers;
use App\Services\Digest\ModelCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class LlmModelForm
{
    public static function configure(Schema $schema): Schema
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

        return $schema
            ->components([
                Select::make('provider')
                    ->required()
                    ->default('openai')
                    ->native(false)
                    ->live()
                    ->options(fn (?string $state) => ModelCatalog::providerOptions($state))
                    ->rules(['max:64', 'not_regex:/\s/'])
                    ->afterStateUpdated(fn (Set $set) => $set('model', null))
                    ->createOptionForm([
                        $id('provider', 'Provider id', 64)
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
                    ->rules(['max:255', 'not_regex:/\s/'])
                    ->validationMessages(['not_regex' => 'A model id has no spaces.'])
                    ->rule(fn (Get $get, ?LlmModel $record) => Rule::unique('llm_models', 'model')
                        ->where('provider', $get('provider'))
                        ->ignore($record?->getKey()))
                    ->createOptionForm([
                        $id('model', 'Model id', 255)
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
                Toggle::make('is_digest_model')
                    ->label('Use for digests')
                    ->helperText(function (Get $get) {
                        $text = 'Session digests and backfills run on this model, through its provider. Marking it unmarks any other. With none marked, the server\'s configured driver and model are used.';
                        $missing = DigestDrivers::missingSetting((string) $get('provider'));

                        return $missing === null ? $text : "{$text} {$missing} is not set on the server, so digests on this model will fail until it is.";
                    })
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                        if ($value && ! in_array($get('provider'), LlmModel::DIGEST_PROVIDERS, true)) {
                            $fail('Only an OpenAI, Anthropic or OpenAI-compatible model can be used for digests.');
                        }
                    }),
                Textarea::make('notes')
                    ->rows(2)
                    ->helperText('Where the prices came from, and when.'),
            ]);
    }
}
