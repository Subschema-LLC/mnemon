<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LlmModels\Pages\CreateLlmModel;
use App\Filament\Resources\LlmModels\Pages\EditLlmModel;
use App\Filament\Resources\LlmModels\Pages\ListLlmModels;
use App\Models\DigestUsage;
use App\Models\LlmModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LlmModelResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_configured_model_has_a_row_to_price(): void
    {
        // Added by the migration, unpriced and unmarked.
        $row = LlmModel::for('openai', config('mnemon.llm.model'));

        $this->assertNotNull($row);
        $this->assertNull($row->price_in_per_mtok);
        $this->assertFalse($row->is_digest_model);
    }

    public function test_list_shows_usage_averages_and_spend_at_the_current_price(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '1', 'price_out_per_mtok' => '10']);
        $unpriced = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-b']);
        $row = ['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 500_000, 'completion_tokens' => 50_000];
        DigestUsage::create($row);
        DigestUsage::create($row);
        DigestUsage::create(['model' => 'gpt-b'] + $row);

        Livewire::test(ListLlmModels::class)
            ->assertCanSeeTableRecords([$m, $unpriced])
            ->assertTableColumnStateSet('calls', 2, $m)
            ->assertTableColumnStateSet('avg_out', 50_000, $m)
            ->assertTableColumnStateSet('spend', '$2.00', $m)
            ->assertTableColumnStateSet('spend', 'no price', $unpriced);
    }

    public function test_a_price_edit_re_prices_recorded_usage(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '1', 'price_out_per_mtok' => '10']);
        DigestUsage::create(['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);

        Livewire::test(EditLlmModel::class, ['record' => $m->getRouteKey()])
            ->fillForm(['price_in_per_mtok' => '3'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ListLlmModels::class)->assertTableColumnStateSet('spend', '$3.00', $m->fresh());
    }

    public function test_create_a_model_and_mark_it_for_digests(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-new', 'price_in_per_mtok' => '0.5', 'price_out_per_mtok' => '4', 'is_digest_model' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('gpt-new', LlmModel::digestModel()->model);
    }

    public function test_only_a_provider_with_a_driver_can_be_marked_for_digests(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'mistral', 'model' => 'mistral-large', 'is_digest_model' => true])
            ->call('create')
            ->assertHasFormErrors(['is_digest_model']);

        // Kept for price comparison, unmarked, it saves.
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'mistral', 'model' => 'mistral-large', 'price_in_per_mtok' => '2', 'price_out_per_mtok' => '6'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_an_anthropic_model_can_be_picked_from_the_list_and_marked_for_digests(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'anthropic'])
            ->assertFormFieldExists('model', fn ($field) => array_key_exists('claude-haiku-5-5', $field->getOptions()))
            ->fillForm(['model' => 'claude-haiku-5-5', 'is_digest_model' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['anthropic', 'claude-haiku-5-5'], [LlmModel::digestModel()->provider, LlmModel::digestModel()->model]);
    }

    public function test_a_model_that_is_not_listed_is_added_with_the_plus_button(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai'])
            ->callFormComponentAction('model', 'createOption', data: ['model' => 'ft:gpt-4o-mini:acme::abc123'])
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['model' => 'ft:gpt-4o-mini:acme::abc123'])
            ->assertFormFieldExists('model', fn ($field) => $field->getOptions()['ft:gpt-4o-mini:acme::abc123'] === 'ft:gpt-4o-mini:acme::abc123 (added by hand)')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(LlmModel::for('openai', 'ft:gpt-4o-mini:acme::abc123'));
    }

    public function test_a_hand_added_model_id_with_a_space_is_refused_in_the_modal(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai'])
            ->callFormComponentAction('model', 'createOption', data: ['model' => 'gpt 5'])
            ->assertHasFormComponentActionErrors(['model']);
    }

    public function test_another_provider_is_added_with_the_plus_button(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->callFormComponentAction('provider', 'createOption', data: ['provider' => 'deepseek'])
            ->assertFormSet(['provider' => 'deepseek'])
            ->callFormComponentAction('model', 'createOption', data: ['model' => 'deepseek-chat'])
            ->fillForm(['price_in_per_mtok' => '0.27', 'price_out_per_mtok' => '1.1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(LlmModel::for('deepseek', 'deepseek-chat'));
    }

    public function test_changing_the_provider_clears_the_model(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-5.6-luna'])
            ->fillForm(['provider' => 'anthropic'])
            ->assertFormSet(['model' => null]);
    }

    public function test_editing_a_row_keeps_its_hand_added_model_selected(): void
    {
        $row = LlmModel::create(['provider' => 'deepseek', 'model' => 'deepseek-reasoner']);

        Livewire::test(EditLlmModel::class, ['record' => $row->getRouteKey()])
            ->assertFormSet(['provider' => 'deepseek', 'model' => 'deepseek-reasoner'])
            ->fillForm(['price_in_per_mtok' => '0.55'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0.55', rtrim(rtrim((string) $row->fresh()->price_in_per_mtok, '0'), '.'));
    }

    public function test_the_same_model_cannot_be_entered_twice_for_a_provider(): void
    {
        LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a']);

        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-a'])
            ->call('create')
            ->assertHasFormErrors(['model']);
    }

    public function test_a_model_id_with_a_space_is_rejected(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-5.6-luna ', 'is_digest_model' => true])
            ->call('create')
            ->assertHasFormErrors(['model']);

        $this->assertNull(LlmModel::digestModel());
    }

    public function test_prices_display_without_padding(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '0.250000']);

        Livewire::test(ListLlmModels::class)->assertTableColumnFormattedStateSet('price_in_per_mtok', '0.25', $m);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-z', 'price_in_per_mtok' => '-1'])
            ->call('create')
            ->assertHasFormErrors(['price_in_per_mtok']);
    }
}
