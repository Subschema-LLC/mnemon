<?php

namespace Tests\Unit\Integrations;

use Mnemon\Entities\LlmModel\InvalidLlmModel;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Integrations\SettingsFiles\YamlLlmModelStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The settings file: what it reads, what it refuses, and how it writes. */
class YamlLlmModelStoreTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/mnemon-overrides-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->folder}/*") ?: []);
        if (is_dir($this->folder)) {
            rmdir($this->folder);
        }
        parent::tearDown();
    }

    private function path(): string
    {
        return "{$this->folder}/LlmModel.yaml";
    }

    private function write(string $yaml): void
    {
        if (! is_dir($this->folder)) {
            mkdir($this->folder, 0777, true);
        }
        file_put_contents($this->path(), $yaml);
    }

    public function test_a_missing_file_is_an_empty_list(): void
    {
        $this->assertTrue((new YamlLlmModelStore($this->path()))->load()->isEmpty());
    }

    public function test_a_saved_list_reads_back_the_same_in_the_next_request(): void
    {
        $list = new LlmModelList([
            new LlmModel('openai', 'gpt-a', 0.25, 0.025, 2.0, true, 'From the pricing page, Nov 2026'),
            new LlmModel('deepseek', 'deepseek-chat', priceInPerMtok: 0.27),
        ]);

        (new YamlLlmModelStore($this->path()))->save($list);
        $read = (new YamlLlmModelStore($this->path()))->load();

        $this->assertEquals($list, $read);
    }

    public function test_the_file_is_plain_and_leaves_out_empty_keys(): void
    {
        (new YamlLlmModelStore($this->path()))->save(new LlmModelList([new LlmModel('openai', 'gpt-a', priceOutPerMtok: 2)]));

        $yaml = (string) file_get_contents($this->path());
        $this->assertStringStartsWith('# Mnemon\'s chat models', $yaml);
        $this->assertStringContainsString("models:\n  -\n    provider: openai\n    model: gpt-a\n    price_out_per_mtok: 2", $yaml);
        $this->assertStringNotContainsString('price_in_per_mtok', substr($yaml, (int) strpos($yaml, 'models:')));
        $this->assertStringNotContainsString('use_for_digests', substr($yaml, (int) strpos($yaml, 'models:')));
    }

    public function test_a_hand_written_file_is_read(): void
    {
        $this->write("models:\n  - { provider: anthropic, model: claude-haiku-5-5, price_in_per_mtok: 1, price_out_per_mtok: 5, use_for_digests: true }\n");

        $model = (new YamlLlmModelStore($this->path()))->load()->digestModel();

        $this->assertSame('anthropic claude-haiku-5-5', $model->key());
        $this->assertSame(5.0, $model->priceOutPerMtok);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function brokenFiles(): array
    {
        return [
            'a typo in a key' => ["models:\n  - { provider: openai, model: a, price_inn_per_mtok: 1 }\n", "unknown key 'price_inn_per_mtok'"],
            'an unknown top-level key' => ["model:\n  - { provider: openai, model: a }\n", "only top-level key is 'models'"],
            'a price that is text' => ["models:\n  - { provider: openai, model: a, price_in_per_mtok: 'cheap' }\n", 'price_in_per_mtok is a number'],
            'a missing model id' => ["models:\n  - { provider: openai }\n", 'provider and model are text'],
            'two digest models' => ["models:\n  - { provider: openai, model: a, use_for_digests: true }\n  - { provider: openai, model: b, use_for_digests: true }\n", 'Only one model'],
            'a model listed twice' => ["models:\n  - { provider: openai, model: a }\n  - { provider: openai, model: a }\n", 'listed twice'],
            'broken YAML' => ["models: [\n", 'LlmModel.yaml'],
        ];
    }

    #[DataProvider('brokenFiles')]
    public function test_a_broken_file_stops_with_the_file_and_the_problem_named(string $yaml, string $problem): void
    {
        $this->write($yaml);

        try {
            (new YamlLlmModelStore($this->path()))->load();
            $this->fail('A broken file loaded.');
        } catch (InvalidLlmModel $error) {
            $this->assertStringContainsString($this->path(), $error->getMessage());
            $this->assertStringContainsString($problem, $error->getMessage());
        }
    }

    public function test_the_file_is_read_once_per_request_and_a_save_is_seen_at_once(): void
    {
        $store = new YamlLlmModelStore($this->path());
        $store->save(new LlmModelList([new LlmModel('openai', 'a')]));

        // A hand edit lands mid-request: this request keeps what it saw.
        $this->write("models:\n  - { provider: openai, model: changed }\n");

        $this->assertSame(['a'], $store->load()->modelsOf('openai'));
        $this->assertSame(['changed'], (new YamlLlmModelStore($this->path()))->load()->modelsOf('openai'));
    }

    public function test_saving_leaves_no_temporary_file_behind(): void
    {
        (new YamlLlmModelStore($this->path()))->save(new LlmModelList([new LlmModel('openai', 'a')]));

        $this->assertSame([$this->path()], glob("{$this->folder}/*"));
    }
}
