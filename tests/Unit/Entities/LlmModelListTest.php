<?php

namespace Tests\Unit\Entities;

use Mnemon\Entities\LlmModel\InvalidLlmModel;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rules of a chat model and of the list it sits in. No framework. */
class LlmModelListTest extends TestCase
{
    public function test_cost_charges_cached_input_at_its_own_price(): void
    {
        $model = new LlmModel('openai', 'a', priceInPerMtok: 1, priceCachedInPerMtok: 0.1, priceOutPerMtok: 10);

        // 600k uncached at $1, 400k cached at $0.10, 100k out at $10.
        $this->assertEqualsWithDelta(0.6 + 0.04 + 1.0, $model->costOf(1_000_000, 400_000, 100_000), 1e-9);
    }

    public function test_cached_input_falls_back_to_the_input_price(): void
    {
        $model = new LlmModel('openai', 'a', priceInPerMtok: 1, priceOutPerMtok: 10);

        $this->assertEqualsWithDelta(1.0, $model->costOf(1_000_000, 400_000, 0), 1e-9);
    }

    public function test_cost_is_unknown_without_input_and_output_prices(): void
    {
        $this->assertNull((new LlmModel('openai', 'a', priceInPerMtok: 1))->costOf(10, 0, 10));
        $this->assertNull((new LlmModel('openai', 'a', priceOutPerMtok: 1))->costOf(10, 0, 10));
    }

    /** @return array<string, array{0: callable(): LlmModel}> */
    public static function brokenModels(): array
    {
        return [
            'an empty provider' => [fn () => new LlmModel('', 'a')],
            'a model id with a space' => [fn () => new LlmModel('openai', 'gpt 5')],
            'a trailing space' => [fn () => new LlmModel('openai', 'gpt-5 ')],
            'a provider id that is too long' => [fn () => new LlmModel(str_repeat('p', 65), 'a')],
            'a negative price' => [fn () => new LlmModel('openai', 'a', priceOutPerMtok: -1)],
            'a provider without a driver marked for digests' => [fn () => new LlmModel('mistral', 'a', usedForDigests: true)],
        ];
    }

    /** @param callable(): LlmModel $make */
    #[DataProvider('brokenModels')]
    public function test_a_model_that_breaks_a_rule_cannot_be_made(callable $make): void
    {
        $this->expectException(InvalidLlmModel::class);
        $make();
    }

    public function test_marking_a_model_for_digests_unmarks_the_others(): void
    {
        $list = (new LlmModelList)
            ->put(new LlmModel('openai', 'a', usedForDigests: true))
            ->put(new LlmModel('anthropic', 'b', usedForDigests: true));

        $this->assertSame('anthropic b', $list->digestModel()->key());
        $this->assertFalse($list->find('openai', 'a')->usedForDigests);
    }

    public function test_a_list_never_holds_two_digest_models_or_one_model_twice(): void
    {
        $this->expectException(InvalidLlmModel::class);
        new LlmModelList([new LlmModel('openai', 'a', usedForDigests: true), new LlmModel('openai', 'b', usedForDigests: true)]);
    }

    public function test_a_model_cannot_be_listed_twice(): void
    {
        $list = new LlmModelList([new LlmModel('openai', 'a')]);

        $this->expectException(InvalidLlmModel::class);
        $list->put(new LlmModel('openai', 'a', priceInPerMtok: 1));
    }

    public function test_putting_with_the_old_key_replaces_and_can_rename(): void
    {
        $list = new LlmModelList([new LlmModel('openai', 'typo', priceInPerMtok: 1), new LlmModel('openai', 'b')]);

        $renamed = $list->put(new LlmModel('openai', 'right', priceInPerMtok: 2), 'openai typo');

        $this->assertNull($renamed->find('openai', 'typo'));
        $this->assertSame(2.0, $renamed->find('openai', 'right')->priceInPerMtok);
        $this->assertSame(['b', 'right'], $renamed->modelsOf('openai'));
    }

    public function test_a_list_is_never_changed_in_place(): void
    {
        $list = new LlmModelList([new LlmModel('openai', 'a')]);

        $list->put(new LlmModel('openai', 'b'));
        $list->remove('openai a');

        $this->assertSame(['a'], $list->modelsOf('openai'));
    }

    public function test_providers_and_models_are_listed_sorted(): void
    {
        $list = new LlmModelList([
            new LlmModel('openai', 'z'), new LlmModel('anthropic', 'b'), new LlmModel('openai', 'a'),
        ]);

        $this->assertSame(['anthropic', 'openai'], $list->providers());
        $this->assertSame(['a', 'z'], $list->modelsOf('openai'));
        $this->assertSame(['anthropic b', 'openai a', 'openai z'], array_map(fn ($m) => $m->key(), $list->all()));
    }
}
