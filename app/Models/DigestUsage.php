<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The tokens one model call used: a session digest, or a vision caption for
 * an uploaded image (`purpose`). Tokens, not dollars: cost is worked out
 * against the model's current price in the settings file (LlmModel::costOf).
 *
 * @property string $provider
 * @property string $model
 * @property string $purpose
 * @property int $prompt_tokens
 * @property int $cached_prompt_tokens
 * @property int $completion_tokens
 * @property int $reasoning_tokens
 * @property bool $succeeded
 */
class DigestUsage extends Model
{
    public const UPDATED_AT = null;

    public const PURPOSE_DIGEST = 'digest';

    public const PURPOSE_VISION = 'vision';

    protected $attributes = [
        'purpose' => self::PURPOSE_DIGEST,
    ];

    protected $fillable = [
        'provider',
        'model',
        'purpose',
        'session_id',
        'turn_start',
        'turn_end',
        'prompt_tokens',
        'cached_prompt_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'succeeded',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    /**
     * Calls and tokens per model, keyed "provider model" (LlmModel::key()).
     * With a purpose, only that purpose: the digest averages calibrate the
     * backfill estimate, and a vision caption's tokens say nothing about a
     * digest's. Without one, every call: what a model has cost.
     *
     * @return array<string, object{calls: int, prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int}>
     */
    public static function totalsByModel(?string $purpose = null): array
    {
        $rows = static::query()
            ->when($purpose !== null, fn ($q) => $q->where('purpose', $purpose))
            ->groupBy('provider', 'model')
            ->selectRaw('provider, model, COUNT(*) as calls, COALESCE(SUM(prompt_tokens),0) as prompt_tokens, COALESCE(SUM(cached_prompt_tokens),0) as cached_prompt_tokens, COALESCE(SUM(completion_tokens),0) as completion_tokens')
            ->toBase()
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals["{$row->provider} {$row->model}"] = (object) [
                'calls' => (int) $row->calls,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'cached_prompt_tokens' => (int) $row->cached_prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
            ];
        }

        return $totals;
    }
}
