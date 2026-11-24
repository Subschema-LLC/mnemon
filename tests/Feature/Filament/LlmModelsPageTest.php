<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LlmModels;
use App\Models\DigestUsage;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;
use Tests\TestCase;

class LlmModelsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function models(): LlmModelList
    {
        return $this->app->make(LlmModelStore::class)->load();
    }

    /** The schema of the action open in the modal, for helpers that default to 'form'. */
    private const MODAL = 'mountedActionSchema0';

    private function edit(string $key): TestAction
    {
        return TestAction::make('edit')->table($key);
    }

    public function test_list_shows_usage_averages_and_spend_at_the_current_price(): void
    {
        $this->addLlmModel('openai', 'gpt-a', in: 1, out: 10);
        $this->addLlmModel('openai', 'gpt-b');
        $row = ['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 500_000, 'completion_tokens' => 50_000];
        DigestUsage::create($row);
        DigestUsage::create($row);
        DigestUsage::create(['model' => 'gpt-b'] + $row);

        Livewire::test(LlmModels::class)
            ->assertCanSeeTableRecords(['openai gpt-a', 'openai gpt-b'])
            ->assertTableColumnStateSet('calls', 2, 'openai gpt-a')
            ->assertTableColumnStateSet('avg_out', 50_000, 'openai gpt-a')
            ->assertTableColumnStateSet('spend', '$2.00', 'openai gpt-a')
            ->assertTableColumnStateSet('spend', 'no price', 'openai gpt-b');
    }

    public function test_vision_calls_count_toward_spend_but_not_the_averages(): void
    {
        $this->addLlmModel('openai', 'gpt-a', in: 1, out: 0);
        $row = ['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 100, 'completion_tokens' => 0];
        DigestUsage::create($row);
        DigestUsage::create(['purpose' => DigestUsage::PURPOSE_VISION, 'prompt_tokens' => 999_900] + $row);

        Livewire::test(LlmModels::class)
            ->assertTableColumnStateSet('calls', 1, 'openai gpt-a')
            ->assertTableColumnStateSet('avg_in', 100, 'openai gpt-a')
            ->assertTableColumnStateSet('vision_calls', 1, 'openai gpt-a')
            ->assertTableColumnStateSet('spend', '$1.00', 'openai gpt-a');
    }

    public function test_a_price_edit_re_prices_recorded_usage(): void
    {
        $this->addLlmModel('openai', 'gpt-a', in: 1, out: 10);
        DigestUsage::create(['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);

        Livewire::test(LlmModels::class)
            ->callAction($this->edit('openai gpt-a'), data: ['price_in_per_mtok' => '3'])
            ->assertHasNoActionErrors()
            ->assertTableColumnStateSet('spend', '$3.00', 'openai gpt-a');

        $this->assertSame(3.0, $this->models()->find('openai', 'gpt-a')->priceInPerMtok);
    }

    public function test_create_a_model_and_mark_it_for_digests(): void
    {
        $this->addLlmModel('openai', 'gpt-old', forDigests: true);

        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'openai', 'model' => 'gpt-new', 'price_in_per_mtok' => '0.5', 'price_out_per_mtok' => '4', 'use_for_digests' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('gpt-new', $this->models()->digestModel()->model);
        $this->assertFalse($this->models()->find('openai', 'gpt-old')->usedForDigests);
    }

    public function test_only_a_provider_with_a_driver_can_be_marked_for_digests(): void
    {
        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'mistral', 'model' => 'mistral-large', 'use_for_digests' => true])
            ->assertHasActionErrors(['use_for_digests']);

        // Kept for price comparison, unmarked, it saves.
        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'mistral', 'model' => 'mistral-large', 'price_in_per_mtok' => '2', 'price_out_per_mtok' => '6'])
            ->assertHasNoActionErrors();

        $this->assertSame(2.0, $this->models()->find('mistral', 'mistral-large')->priceInPerMtok);
    }

    public function test_an_anthropic_model_can_be_picked_from_the_list_and_marked_for_digests(): void
    {
        Livewire::test(LlmModels::class)
            ->mountAction('create')
            ->fillForm(['provider' => 'anthropic'])
            ->assertFormFieldExists('model', fn ($field) => array_key_exists('claude-haiku-5-5', $field->getOptions()))
            ->fillForm(['model' => 'claude-haiku-5-5', 'use_for_digests' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('anthropic claude-haiku-5-5', $this->models()->digestModel()->key());
    }

    public function test_a_model_that_is_not_listed_is_added_with_the_plus_button(): void
    {
        $id = 'ft:gpt-4o-mini:acme::abc123';

        Livewire::test(LlmModels::class)
            ->mountAction('create')
            ->fillForm(['provider' => 'openai'])
            ->callFormComponentAction('model', 'createOption', data: ['model' => $id], formName: self::MODAL)
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['model' => $id], self::MODAL)
            ->assertFormFieldExists('model', fn ($field) => $field->getOptions()[$id] === "{$id} (added by hand)")
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertNotNull($this->models()->find('openai', $id));
    }

    public function test_a_hand_added_model_id_with_a_space_is_refused_in_the_modal(): void
    {
        Livewire::test(LlmModels::class)
            ->mountAction('create')
            ->fillForm(['provider' => 'openai'])
            ->callFormComponentAction('model', 'createOption', data: ['model' => 'gpt 5'], formName: self::MODAL)
            ->assertHasFormComponentActionErrors(['model']);
    }

    public function test_another_provider_is_added_with_the_plus_button(): void
    {
        Livewire::test(LlmModels::class)
            ->mountAction('create')
            ->callFormComponentAction('provider', 'createOption', data: ['provider' => 'deepseek'], formName: self::MODAL)
            ->assertFormSet(['provider' => 'deepseek'], self::MODAL)
            ->callFormComponentAction('model', 'createOption', data: ['model' => 'deepseek-chat'], formName: self::MODAL)
            ->fillForm(['price_in_per_mtok' => '0.27', 'price_out_per_mtok' => '1.1'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1.1, $this->models()->find('deepseek', 'deepseek-chat')->priceOutPerMtok);
    }

    public function test_changing_the_provider_clears_the_model(): void
    {
        Livewire::test(LlmModels::class)
            ->mountAction('create')
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-5.6-luna'])
            ->fillForm(['provider' => 'anthropic'])
            ->assertFormSet(['model' => null], self::MODAL);
    }

    public function test_editing_a_model_keeps_its_hand_added_id_selected(): void
    {
        $this->addLlmModel('deepseek', 'deepseek-reasoner');

        Livewire::test(LlmModels::class)
            ->mountAction($this->edit('deepseek deepseek-reasoner'))
            ->assertFormSet(['provider' => 'deepseek', 'model' => 'deepseek-reasoner'], self::MODAL)
            ->fillForm(['price_in_per_mtok' => '0.55'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(0.55, $this->models()->find('deepseek', 'deepseek-reasoner')->priceInPerMtok);
    }

    public function test_renaming_a_model_replaces_it(): void
    {
        $this->addLlmModel('openai', 'gpt-typo', in: 1, out: 2);

        Livewire::test(LlmModels::class)
            ->callAction($this->edit('openai gpt-typo'), data: ['model' => 'gpt-right'])
            ->assertHasNoActionErrors();

        $this->assertNull($this->models()->find('openai', 'gpt-typo'));
        $this->assertSame(1.0, $this->models()->find('openai', 'gpt-right')->priceInPerMtok);
    }

    public function test_the_same_model_cannot_be_entered_twice_for_a_provider(): void
    {
        $this->addLlmModel('openai', 'gpt-a');
        $this->addLlmModel('openai', 'gpt-b');

        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'openai', 'model' => 'gpt-a'])
            ->assertHasActionErrors(['model']);

        // Saving an edit without renaming is not a duplicate of itself;
        // renaming onto another model is.
        Livewire::test(LlmModels::class)
            ->callAction($this->edit('openai gpt-a'), data: ['price_in_per_mtok' => '1'])
            ->assertHasNoActionErrors()
            ->callAction($this->edit('openai gpt-a'), data: ['model' => 'gpt-b'])
            ->assertHasActionErrors(['model']);
    }

    public function test_a_model_id_with_a_space_is_rejected(): void
    {
        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'openai', 'model' => 'gpt-5.6-luna ', 'use_for_digests' => true])
            ->assertHasActionErrors(['model']);

        $this->assertNull($this->models()->digestModel());
    }

    public function test_prices_display_without_padding(): void
    {
        $this->addLlmModel('openai', 'gpt-a', in: 0.25);

        Livewire::test(LlmModels::class)
            ->assertTableColumnFormattedStateSet('price_in_per_mtok', '0.25', 'openai gpt-a');
    }

    public function test_a_negative_price_is_rejected(): void
    {
        Livewire::test(LlmModels::class)
            ->callAction('create', data: ['provider' => 'openai', 'model' => 'gpt-z', 'price_in_per_mtok' => '-1'])
            ->assertHasActionErrors(['price_in_per_mtok']);
    }

    public function test_deleting_a_model_removes_it_from_the_file_and_keeps_its_usage(): void
    {
        $this->addLlmModel('openai', 'gpt-a');
        DigestUsage::create(['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 1]);

        Livewire::test(LlmModels::class)
            ->callAction(TestAction::make('delete')->table('openai gpt-a'))
            ->assertCanNotSeeTableRecords(['openai gpt-a']);

        $this->assertNull($this->models()->find('openai', 'gpt-a'));
        $this->assertSame(1, DigestUsage::count());
    }
}
