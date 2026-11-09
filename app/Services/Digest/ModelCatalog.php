<?php

namespace App\Services\Digest;

use App\Models\LlmModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The models the admin panel offers for a provider. Where the server holds
 * the provider's key, the list comes from the provider's own models endpoint,
 * so it stays current without a release; otherwise, or when that call fails,
 * it is the models Mnemon is configured with and the rows already entered.
 * Any other model id can still be added by hand.
 */
class ModelCatalog
{
    /** Seconds a fetched list is kept. A failed fetch is retried sooner. */
    public const TTL = 3600;

    public const FAILURE_TTL = 300;

    /** Known Anthropic models, for when the server has no key to list them with. */
    public const ANTHROPIC_FALLBACK = [
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-opus-5-5' => 'Claude Opus 5.5',
    ];

    /**
     * Provider id => label: the digest providers first, then any other
     * provider already entered for price comparison, then $current.
     *
     * @return array<string, string>
     */
    public static function providerOptions(?string $current = null): array
    {
        $options = [];
        foreach (DigestDrivers::LABELS as $id => $label) {
            $options[$id] = $id === 'openai-compatible' && ($host = parse_url((string) config('mnemon.digest.compatible.base_url'), PHP_URL_HOST))
                ? "{$label} ({$host})"
                : $label;
        }

        foreach (LlmModel::query()->distinct()->orderBy('provider')->pluck('provider') as $provider) {
            $options[$provider] ??= "{$provider} (price comparison only)";
        }

        if ($current !== null && $current !== '' && ! isset($options[$current])) {
            $options[$current] = "{$current} (price comparison only)";
        }

        return $options;
    }

    /**
     * Model id => label for a provider. $current is always included, so a
     * model added by hand stays selected.
     *
     * @return array<string, string>
     */
    public static function modelOptions(?string $provider, ?string $current = null): array
    {
        $provider = (string) $provider;
        $options = $provider === '' ? [] : self::listed($provider);

        foreach (self::configured($provider) as $id) {
            $options[$id] ??= $id;
        }

        foreach (LlmModel::query()->where('provider', $provider)->orderBy('model')->pluck('model') as $id) {
            $options[$id] ??= $id;
        }

        if ($current !== null && $current !== '' && ! isset($options[$current])) {
            $options[$current] = "{$current} (added by hand)";
        }

        return $options;
    }

    /**
     * The provider's own list, cached.
     *
     * @return array<string, string>
     */
    private static function listed(string $provider): array
    {
        if (! array_key_exists($provider, DigestDrivers::LABELS) || DigestDrivers::missingSetting($provider) !== null) {
            return $provider === 'anthropic' ? self::ANTHROPIC_FALLBACK : [];
        }

        $key = self::cacheKey($provider);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $models = self::fetch($provider);
        Cache::put($key, $models ?? [], $models === null ? self::FAILURE_TTL : self::TTL);

        return $models ?? ($provider === 'anthropic' ? self::ANTHROPIC_FALLBACK : []);
    }

    /**
     * @return array<string, string>|null Null when the provider could not be asked.
     */
    private static function fetch(string $provider): ?array
    {
        try {
            $http = Http::timeout(5)->acceptJson();

            $response = match ($provider) {
                'openai' => $http->withToken((string) (config('services.openai.api_key') ?? env('OPENAI_API_KEY')))
                    ->get('https://api.openai.com/v1/models'),
                'anthropic' => $http->withHeaders([
                    'x-api-key' => config('services.anthropic.api_key'),
                    'anthropic-version' => AnthropicDigestDriver::API_VERSION,
                ])->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
                'openai-compatible' => (filled($key = config('mnemon.digest.compatible.api_key')) ? $http->withToken($key) : $http)
                    ->get(rtrim((string) config('mnemon.digest.compatible.base_url'), '/').'/models'),
            };

            if ($response->failed() || ! is_array($response->json('data'))) {
                Log::info('mnemon: could not list models', ['provider' => $provider, 'status' => $response->status()]);

                return null;
            }

            $rows = array_filter($response->json('data'), fn ($row) => is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '');

            if ($provider === 'openai') {
                // Every model OpenAI serves, embeddings and audio included;
                // keep the ones that answer chat completions, newest first.
                $rows = array_filter($rows, fn ($row) => self::isOpenAiChatModel($row['id']));
                usort($rows, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
            }

            $models = [];
            foreach ($rows as $row) {
                $name = $row['display_name'] ?? null;
                $models[$row['id']] = is_string($name) && $name !== '' && $name !== $row['id'] ? "{$name} ({$row['id']})" : $row['id'];
            }

            return $models;
        } catch (\Exception $e) {
            Log::info('mnemon: could not list models', ['provider' => $provider, 'message' => $e->getMessage()]);

            return null;
        }
    }

    public static function isOpenAiChatModel(string $id): bool
    {
        return preg_match('/^(gpt-|o\d|chatgpt-)/', $id) === 1
            && preg_match('/(audio|realtime|tts|transcribe|image|search|embedding|moderation|instruct|codex|computer-use|deep-research)/', $id) !== 1;
    }

    /**
     * @return array<int, string>
     */
    private static function configured(string $provider): array
    {
        return array_values(array_filter(match ($provider) {
            'openai' => [config('mnemon.digest.openai_model'), config('mnemon.llm.model')],
            'anthropic' => [config('mnemon.digest.anthropic_model')],
            'openai-compatible' => [config('mnemon.digest.compatible.model')],
            default => [],
        }, fn ($id) => is_string($id) && $id !== ''));
    }

    private static function cacheKey(string $provider): string
    {
        // For an OpenAI-compatible service the base URL decides what is
        // listed: a changed one is a new list.
        return 'mnemon:llm-models:'.$provider
            .($provider === 'openai-compatible' ? ':'.md5((string) config('mnemon.digest.compatible.base_url')) : '');
    }
}
