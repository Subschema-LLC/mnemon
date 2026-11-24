<?php

/**
 * The chat models Mnemon knows, and the rules between them: one entry per
 * provider and model, and at most one model marked for digests. The list is
 * immutable; put() and remove() return a new one, so a list that exists
 * always keeps the rules and a store can save it as it is.
 */

namespace Mnemon\Entities\LlmModel;

final class LlmModelList
{
    /** @var array<string, LlmModel> by key */
    private array $models = [];

    /** @param list<LlmModel> $models */
    public function __construct(array $models = [])
    {
        foreach ($models as $model) {
            if (isset($this->models[$model->key()])) {
                throw new InvalidLlmModel("{$model->provider} {$model->model} is listed twice.");
            }
            $this->models[$model->key()] = $model;
        }
        $marked = array_filter(
            $this->models,
            static fn (LlmModel $model) => $model->usedForDigests,
        );
        if (count($marked) > 1) {
            throw new InvalidLlmModel('Only one model can be marked for digests.');
        }
    }

    /** @return list<LlmModel> sorted by provider, then model */
    public function all(): array
    {
        $models = $this->models;
        ksort($models);

        return array_values($models);
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    public function find(string $provider, string $model): ?LlmModel
    {
        return $this->models["{$provider} {$model}"] ?? null;
    }

    public function findByKey(string $key): ?LlmModel
    {
        return $this->models[$key] ?? null;
    }

    public function digestModel(): ?LlmModel
    {
        foreach ($this->models as $model) {
            if ($model->usedForDigests) {
                return $model;
            }
        }

        return null;
    }

    /** @return list<string> every provider listed, sorted */
    public function providers(): array
    {
        $providers = array_unique(
            array_map(static fn (LlmModel $model) => $model->provider, $this->models),
        );
        sort($providers);

        return $providers;
    }

    /** @return list<string> the models listed for one provider, sorted */
    public function modelsOf(string $provider): array
    {
        $ids = [];
        foreach ($this->models as $model) {
            if ($model->provider === $provider) {
                $ids[] = $model->model;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Adds a model, or replaces the one at $replacing (its key before an
     * edit). Marking a model for digests unmarks any other.
     */
    public function put(LlmModel $model, ?string $replacing = null): self
    {
        $models = $this->models;
        if ($replacing !== null) {
            unset($models[$replacing]);
        }
        if (isset($models[$model->key()])) {
            throw new InvalidLlmModel("{$model->provider} {$model->model} is already listed.");
        }
        if ($model->usedForDigests) {
            $models = array_map(
                static fn (LlmModel $other) => $other->withDigestMark(false),
                $models,
            );
        }
        $models[$model->key()] = $model;

        return new self(array_values($models));
    }

    public function remove(string $key): self
    {
        $models = $this->models;
        unset($models[$key]);

        return new self(array_values($models));
    }
}
