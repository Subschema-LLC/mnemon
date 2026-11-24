<?php

/**
 * A chat model Mnemon can run on, and what it costs. Prices are dollars per
 * million tokens and stay null until someone enters them: Mnemon does not
 * guess prices. Only a provider with a digest driver can run digests; any
 * other is kept for price comparison. An LlmModel is a value: a change makes
 * a new one, and the list it sits in keeps the rules between models.
 */

namespace Mnemon\Entities\LlmModel;

final class LlmModel
{
    /** Providers with a digest driver. */
    public const DIGEST_PROVIDERS = ['openai', 'anthropic', 'openai-compatible'];

    public const PROVIDER_MAX_LENGTH = 64;

    public const MODEL_MAX_LENGTH = 255;

    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly ?float $priceInPerMtok = null,
        public readonly ?float $priceCachedInPerMtok = null,
        public readonly ?float $priceOutPerMtok = null,
        public readonly bool $usedForDigests = false,
        public readonly ?string $notes = null,
    ) {
        self::checkId('provider', $provider, self::PROVIDER_MAX_LENGTH);
        // A model id is sent to the provider verbatim and matched exactly
        // against recorded usage: one stray space and every digest fails.
        self::checkId('model', $model, self::MODEL_MAX_LENGTH);
        foreach ([$priceInPerMtok, $priceCachedInPerMtok, $priceOutPerMtok] as $price) {
            if ($price !== null && $price < 0) {
                throw new InvalidLlmModel("A price cannot be negative ({$this->key()}).");
            }
        }
        if ($usedForDigests && ! self::canDigestWith($provider)) {
            throw new InvalidLlmModel(
                'Only an OpenAI, Anthropic or OpenAI-compatible model can run digests '
                ."({$this->key()})."
            );
        }
    }

    public static function canDigestWith(string $provider): bool
    {
        return in_array($provider, self::DIGEST_PROVIDERS, true);
    }

    /** Provider and model together, unique in a list. Neither holds a space. */
    public function key(): string
    {
        return "{$this->provider} {$this->model}";
    }

    public function withDigestMark(bool $usedForDigests): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->priceInPerMtok,
            $this->priceCachedInPerMtok,
            $this->priceOutPerMtok,
            $usedForDigests,
            $this->notes,
        );
    }

    /**
     * What a call with this usage costs at these prices, or null when a
     * price it needs is missing. Cached input falls back to the input price.
     */
    public function costOf(
        int $promptTokens,
        int $cachedPromptTokens,
        int $completionTokens,
    ): ?float {
        if ($this->priceInPerMtok === null || $this->priceOutPerMtok === null) {
            return null;
        }
        $cachedPrice = $this->priceCachedInPerMtok ?? $this->priceInPerMtok;
        $cached = min($cachedPromptTokens, $promptTokens);
        $dollarsPerMillion = ($promptTokens - $cached) * $this->priceInPerMtok
            + $cached * $cachedPrice
            + $completionTokens * $this->priceOutPerMtok;

        return $dollarsPerMillion / 1_000_000;
    }

    private static function checkId(string $name, string $value, int $maxLength): void
    {
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            throw new InvalidLlmModel("A {$name} id is required and has no spaces ('{$value}').");
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidLlmModel("A {$name} id is at most {$maxLength} characters.");
        }
    }
}
