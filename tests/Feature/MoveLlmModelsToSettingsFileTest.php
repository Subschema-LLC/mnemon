<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mnemon\Entities\LlmModel\InvalidLlmModel;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;
use Tests\TestCase;

/** The migration that moves llm_models rows into the settings file. */
class MoveLlmModelsToSettingsFileTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_23_000000_move_llm_models_to_the_settings_file.php');
    }

    private function store(): LlmModelStore
    {
        return $this->app->make(LlmModelStore::class);
    }

    /** The table as it was before this migration, with these rows. */
    private function tableWith(array $rows): void
    {
        $this->store()->save(new LlmModelList);
        $this->migration()->down();
        $now = now();
        foreach ($rows as $index => $row) {
            DB::table('llm_models')->insert($row + [
                'provider' => 'openai', 'is_digest_model' => false,
                'created_at' => $now, 'updated_at' => $now->copy()->addSeconds($index),
            ]);
        }
    }

    public function test_rows_move_to_the_file_with_their_prices_and_the_table_is_dropped(): void
    {
        $this->tableWith([
            ['model' => 'gpt-a', 'price_in_per_mtok' => '0.250000', 'price_out_per_mtok' => '2.000000',
                'is_digest_model' => true, 'notes' => 'list price'],
            ['provider' => 'mistral', 'model' => 'mistral-large', 'price_cached_in_per_mtok' => '0.1'],
        ]);

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('llm_models'));
        $models = $this->store()->load();
        $this->assertEquals(new LlmModel('openai', 'gpt-a', 0.25, null, 2.0, true, 'list price'), $models->find('openai', 'gpt-a'));
        $this->assertSame(0.1, $models->find('mistral', 'mistral-large')->priceCachedInPerMtok);
    }

    public function test_if_two_rows_were_marked_the_most_recently_saved_keeps_the_mark(): void
    {
        $this->tableWith([
            ['model' => 'older', 'is_digest_model' => true],
            ['model' => 'newer', 'is_digest_model' => true],
        ]);

        $this->migration()->up();

        $this->assertSame('newer', $this->store()->load()->digestModel()->model);
    }

    public function test_a_file_that_already_lists_models_is_kept(): void
    {
        $this->tableWith([['model' => 'from-the-table']]);
        $this->store()->save(new LlmModelList([new LlmModel('anthropic', 'from-the-file')]));

        $this->migration()->up();

        $this->assertSame(['anthropic'], $this->store()->load()->providers());
        $this->assertFalse(Schema::hasTable('llm_models'));
    }

    public function test_a_row_that_breaks_a_rule_stops_the_migration_and_keeps_the_table(): void
    {
        $this->tableWith([['model' => 'gpt 5']]);

        try {
            $this->migration()->up();
            $this->fail('The migration went on past a broken row.');
        } catch (InvalidLlmModel) {
            $this->assertTrue(Schema::hasTable('llm_models'));
            $this->assertSame(1, DB::table('llm_models')->count());
        }
    }

    public function test_rolling_back_puts_the_files_models_back_in_the_table(): void
    {
        $this->store()->save(new LlmModelList([new LlmModel('openai', 'gpt-a', 1, null, 2, true)]));

        $this->migration()->down();

        $row = DB::table('llm_models')->first();
        $this->assertSame(['openai', 'gpt-a', 1], [$row->provider, $row->model, (int) $row->is_digest_model]);
        $this->assertEqualsWithDelta(2.0, (float) $row->price_out_per_mtok, 1e-9);
    }
}
