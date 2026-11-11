<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The tokens one model call used: a session digest, or a vision caption for
 * an uploaded image (`purpose`). Tokens, not dollars: cost is worked out
 * against the model's current price (LlmModel::costOf).
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
}
