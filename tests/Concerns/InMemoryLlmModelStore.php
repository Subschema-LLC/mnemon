<?php

namespace Tests\Concerns;

use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;

/**
 * The model list kept in memory, so no test reads or writes a settings file.
 * Tests\TestCase binds it before the migrations run, so the migration that
 * moves llm_models rows saves them here.
 */
final class InMemoryLlmModelStore implements LlmModelStore
{
    public int $saves = 0;

    private LlmModelList $models;

    public function __construct()
    {
        $this->models = new LlmModelList;
    }

    public function load(): LlmModelList
    {
        return $this->models;
    }

    public function save(LlmModelList $models): void
    {
        $this->models = $models;
        $this->saves++;
    }
}
