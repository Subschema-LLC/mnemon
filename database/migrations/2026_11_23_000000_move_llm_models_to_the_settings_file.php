<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;

/**
 * The chat models and their prices are configuration, so they move from the
 * llm_models table to the settings file (LlmModel.yaml in the overrides
 * folder), which the admin panel now writes.
 *
 * The rows are written to the file, then the table is dropped. A file that
 * already lists models is the operator's and is kept as it is. A row that
 * breaks a rule stops the migration before the table is dropped, so nothing
 * is lost. The table never held more than one marked row on purpose; if two
 * saves at once left two, the most recently saved keeps the mark, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_models')) {
            return;
        }

        $store = app(LlmModelStore::class);
        if ($store->load()->isEmpty()) {
            $store->save($this->rowsAsList());
        }

        Schema::drop('llm_models');
    }

    public function down(): void
    {
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('openai');
            $table->string('model');
            $table->decimal('price_in_per_mtok', 12, 6)->nullable();
            $table->decimal('price_cached_in_per_mtok', 12, 6)->nullable();
            $table->decimal('price_out_per_mtok', 12, 6)->nullable();
            $table->boolean('is_digest_model')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model']);
        });

        foreach (app(LlmModelStore::class)->load()->all() as $model) {
            DB::table('llm_models')->insert([
                'provider' => $model->provider,
                'model' => $model->model,
                'price_in_per_mtok' => $model->priceInPerMtok,
                'price_cached_in_per_mtok' => $model->priceCachedInPerMtok,
                'price_out_per_mtok' => $model->priceOutPerMtok,
                'is_digest_model' => $model->usedForDigests,
                'notes' => $model->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function rowsAsList(): LlmModelList
    {
        $rows = DB::table('llm_models')->orderByDesc('updated_at')->orderByDesc('id')->get();
        $price = fn ($value) => $value === null ? null : (float) $value;
        $marked = false;
        $models = [];
        foreach ($rows as $row) {
            $digest = (bool) $row->is_digest_model && ! $marked
                && LlmModel::canDigestWith(trim($row->provider));
            $marked = $marked || $digest;
            $models[] = new LlmModel(
                trim($row->provider),
                trim($row->model),
                $price($row->price_in_per_mtok),
                $price($row->price_cached_in_per_mtok),
                $price($row->price_out_per_mtok),
                $digest,
                $row->notes,
            );
        }

        return new LlmModelList($models);
    }
};
