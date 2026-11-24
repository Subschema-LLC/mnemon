<?php

/**
 * Keeps the list of chat models in a YAML settings file, LlmModel.yaml in the
 * overrides folder, which belongs to whoever runs Mnemon: no release ships or
 * overwrites it. The admin panel and a person editing by hand write the same
 * file. A missing file is an empty list.
 *
 * Reading checks every key and value, and stops with the file and the entry
 * named, so a typo never passes silently. Writing goes to a temporary file
 * that is read back and checked before it replaces the old one, so a save can
 * never leave a file that would not load. Saving rewrites the file, so only
 * the header comment survives an edit in the panel.
 */

namespace Mnemon\Integrations\SettingsFiles;

use Mnemon\Entities\LlmModel\InvalidLlmModel;
use Mnemon\Entities\LlmModel\LlmModel;
use Mnemon\Entities\LlmModel\LlmModelList;
use Mnemon\Entities\LlmModel\LlmModelStore;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlLlmModelStore implements LlmModelStore
{
    private const KEYS = [
        'provider', 'model', 'price_in_per_mtok', 'price_cached_in_per_mtok',
        'price_out_per_mtok', 'use_for_digests', 'notes',
    ];

    private const HEADER = <<<'YAML'
        # Mnemon's chat models and what they cost, in dollars per million tokens.
        # Edit them in the admin panel (LLM models) or here; a change applies to the
        # next request. Saving in the panel rewrites this file and keeps only this
        # comment. At most one model has use_for_digests: true, and only an openai,
        # anthropic or openai-compatible one. Keys: provider, model,
        # price_in_per_mtok, price_cached_in_per_mtok, price_out_per_mtok,
        # use_for_digests, notes.

        YAML;

    private ?LlmModelList $loaded = null;

    public function __construct(private readonly string $path) {}

    public function load(): LlmModelList
    {
        return $this->loaded ??= is_file($this->path)
            ? self::parse((string) file_get_contents($this->path), $this->path)
            : new LlmModelList;
    }

    public function save(LlmModelList $models): void
    {
        $folder = dirname($this->path);
        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new \RuntimeException("Cannot create the settings folder {$folder}.");
        }
        $entries = array_map(self::toArray(...), $models->all());
        $yaml = self::HEADER.Yaml::dump(['models' => $entries], 4, 2);
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temporary, $yaml) === false) {
            throw new \RuntimeException("Cannot write {$temporary}.");
        }
        try {
            self::parse($yaml, $this->path);
            if (! rename($temporary, $this->path)) {
                throw new \RuntimeException("Cannot replace {$this->path}.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->loaded = $models;
    }

    private static function parse(string $yaml, string $path): LlmModelList
    {
        try {
            $data = Yaml::parse($yaml) ?? [];
        } catch (ParseException $error) {
            throw new InvalidLlmModel("{$path}: {$error->getMessage()}");
        }
        if (! is_array($data) || array_diff(array_keys($data), ['models']) !== []) {
            throw new InvalidLlmModel("{$path}: the only top-level key is 'models'.");
        }
        $models = [];
        foreach ($data['models'] ?? [] as $index => $entry) {
            $models[] = self::fromArray($entry, "{$path}, model ".($index + 1));
        }
        try {
            return new LlmModelList($models);
        } catch (InvalidLlmModel $error) {
            throw new InvalidLlmModel("{$path}: {$error->getMessage()}");
        }
    }

    private static function fromArray(mixed $entry, string $where): LlmModel
    {
        if (! is_array($entry)) {
            throw new InvalidLlmModel("{$where}: each model is a set of keys.");
        }
        $unknown = array_diff(array_keys($entry), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidLlmModel("{$where}: unknown key '".implode("', '", $unknown)."'.");
        }
        $price = static function (string $key) use ($entry, $where): ?float {
            $value = $entry[$key] ?? null;
            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                throw new InvalidLlmModel("{$where}: {$key} is a number or empty.");
            }

            return $value === null ? null : (float) $value;
        };
        $digests = $entry['use_for_digests'] ?? false;
        $notes = $entry['notes'] ?? null;
        if (! is_bool($digests) || ($notes !== null && ! is_string($notes))
            || ! is_string($entry['provider'] ?? null) || ! is_string($entry['model'] ?? null)) {
            throw new InvalidLlmModel(
                "{$where}: provider and model are text, use_for_digests true or false, notes text."
            );
        }
        try {
            return new LlmModel(
                $entry['provider'],
                $entry['model'],
                $price('price_in_per_mtok'),
                $price('price_cached_in_per_mtok'),
                $price('price_out_per_mtok'),
                $digests,
                $notes,
            );
        } catch (InvalidLlmModel $error) {
            throw new InvalidLlmModel("{$where}: {$error->getMessage()}");
        }
    }

    /** @return array<string, mixed> the model's keys, leaving out empty ones */
    private static function toArray(LlmModel $model): array
    {
        return array_filter([
            'provider' => $model->provider,
            'model' => $model->model,
            'price_in_per_mtok' => $model->priceInPerMtok,
            'price_cached_in_per_mtok' => $model->priceCachedInPerMtok,
            'price_out_per_mtok' => $model->priceOutPerMtok,
            'use_for_digests' => $model->usedForDigests ?: null,
            'notes' => $model->notes,
        ], static fn (mixed $value) => $value !== null);
    }
}
