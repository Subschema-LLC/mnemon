<?php

/**
 * The one place each FIDES contract is bound to the integration that fulfils
 * it, even where Laravel could work the binding out. Swapping how something
 * is stored changes a line here and nowhere else. Listed in
 * bootstrap/providers.php; it extends ServiceProvider because Laravel loads
 * nothing else (an edge exception in fides/arch-check.php).
 *
 * Bindings are scoped: one instance per request (or job), so a store reads
 * its file once per request.
 */

namespace Mnemon\Delivery;

use Illuminate\Support\ServiceProvider;
use Mnemon\Entities\LlmModel\LlmModelStore;
use Mnemon\Integrations\SettingsFiles\YamlLlmModelStore;

final class Wiring extends ServiceProvider
{
    public function register(): void
    {
        $overrides = (string) config('mnemon.overrides_path');

        $this->app->scoped(
            LlmModelStore::class,
            static fn () => new YamlLlmModelStore("{$overrides}/LlmModel.yaml"),
        );
    }
}
