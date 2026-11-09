<?php

namespace App\Services\Digest;

use App\Models\LlmModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * What every digest driver shares: the prompt, the model choice, the failure
 * rules, and reading the proposals out of the answer. A driver supplies the
 * provider's request and where its answer and usage sit in the response.
 */
abstract class DigestDriver
{
    /** @var array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, reasoning_tokens: int}|null */
    protected ?array $lastUsage = null;

    protected ?string $lastModel = null;

    /** The provider name usage is recorded and priced under. */
    abstract public function provider(): string;

    /** The model to use when none of this provider's is marked in the admin panel. */
    abstract protected function configuredModel(): ?string;

    /**
     * Throws when the server is not set up to call this provider. Such a
     * failure is the same on every retry.
     *
     * @throws DigestUnavailable
     */
    abstract protected function ensureConfigured(): void;

    /** Sends the request; a transport failure throws ConnectionException. */
    abstract protected function send(string $model, string $system, string $user): Response;

    /** The answer's text, from a successful response. */
    abstract protected function contentFrom(Response $response): ?string;

    /**
     * @return array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, reasoning_tokens: int}|null
     */
    abstract protected function usageFrom(Response $response): ?array;

    /**
     * Token usage of the most recent digest() call; null before a call or
     * when the response reported none.
     *
     * @return array{prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int, reasoning_tokens: int}|null
     */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * The model the most recent digest() call asked; null before a call.
     */
    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    /**
     * The model digests run on: the one marked in the admin panel when it is
     * this provider's, else the configured one.
     */
    public function model(): string
    {
        $marked = LlmModel::digestModel();
        if ($marked !== null && $marked->provider === $this->provider() && $marked->model !== '') {
            return $marked->model;
        }

        return (string) $this->configuredModel();
    }

    /**
     * The system prompt for a digest of this context. Public so the size
     * brain_status advertises is measured on the real prompt.
     *
     * @param  array<string, mixed>  $context
     */
    public function systemPrompt(array $context): string
    {
        // The session's project is known from where it ran; say so, so the
        // model writes for that project. The service enforces the filing.
        $sessionWing = isset($context['session_wing'])
            ? "\nThis session ran in the project whose wing is '{$context['session_wing']}'. File project-specific drawers there; ".
              "use another wing only for content that is not about this project (a person, a cross-project decision).\n"
            : '';

        return "You are Mnemon's session digester. Read a Claude Code transcript and extract drawers worth storing. ".
            "Respond with strict JSON: {\"proposals\":[{\"content\":\"…\",\"wing_slug\":\"…\",\"room_slug\":\"…\",\"confidence\":0.0-1.0,\"propose_new_wing\":bool,\"propose_new_room\":bool,\"rationale\":\"…\"}]}.\n".
            'Existing wings: '.json_encode($context['existing_wings'] ?? [])."\n".
            'Existing rooms per wing (wing_id keys): '.json_encode($context['existing_rooms_per_wing'] ?? [])."\n".
            'Drawers already captured this session (avoid duplicates): '.json_encode($context['recent_drawers'] ?? []).
            $sessionWing;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>> The model's proposals; empty when it found nothing worth keeping.
     *
     * @throws DigestUnavailable When the model could not be asked or its answer could not be read.
     */
    public function digest(string $transcript, array $context): array
    {
        $this->lastUsage = null;
        $this->lastModel = null;

        $this->ensureConfigured();

        $model = $this->lastModel = $this->model();
        $system = $this->systemPrompt($context);

        // A timeout does not come back as an unsuccessful response -- the HTTP
        // client throws. Either way the model was never heard from, which is
        // not the same as the model finding nothing: returning [] for both had
        // the slice recorded as digested and its turns lost.
        try {
            $response = $this->send($model, $system, "Transcript:\n\n".$transcript);
        } catch (ConnectionException $e) {
            Log::warning("mnemon: {$this->provider()} digest call could not complete", [
                'reason' => $e->getMessage(),
            ]);

            // The transport's own message (a cURL error and its timings) goes
            // to the log, not to the client.
            throw new DigestUnavailable('the model call timed out or could not connect', retryable: true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning("mnemon: {$this->provider()} digest call failed", ['status' => $status]);

            // 429 and 5xx clear on their own; any other 4xx (a bad key, a
            // rejected request) fails the same way on every retry.
            throw new DigestUnavailable(
                "the model call failed with HTTP {$status}",
                retryable: $status === 429 || $status >= 500,
            );
        }

        $this->lastUsage = $this->usageFrom($response);

        return $this->proposalsFrom($this->contentFrom($response));
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws DigestUnavailable
     */
    protected function proposalsFrom(?string $content): array
    {
        $parsed = self::decodeJson($content);

        if (! is_array($parsed) || ! isset($parsed['proposals']) || ! is_array($parsed['proposals'])) {
            Log::warning("mnemon: {$this->provider()} digest returned unparseable content");

            throw new DigestUnavailable('the model answered with content that is not a proposals list', retryable: true);
        }

        // Only what the service can persist. One proposal it cannot -- no
        // content, content that is not text, a wing that is not a name --
        // used to throw mid-loop after earlier drawers were written, and the
        // client's retry wrote them again.
        $usable = [];
        foreach ($parsed['proposals'] as $p) {
            if (is_array($p) && is_string($p['confidence'] ?? null) && is_numeric($p['confidence'])) {
                $p['confidence'] = (float) $p['confidence'];
            }
            // An empty slug is no slug: the service then picks the default
            // room rather than filing under a room with no name.
            foreach (['wing_slug', 'room_slug'] as $key) {
                if (is_array($p) && ($p[$key] ?? null) === '') {
                    $p[$key] = null;
                }
            }
            if ($this->usable($p)) {
                $usable[] = $p;
            }
        }

        if (($dropped = count($parsed['proposals']) - count($usable)) > 0) {
            Log::info('mnemon: digest proposals dropped as unusable', ['dropped' => $dropped, 'kept' => count($usable)]);
        }

        return $usable;
    }

    /**
     * The JSON object in a model's answer. Models without a JSON mode (some
     * OpenAI-compatible servers) wrap it in a Markdown fence or print their
     * reasoning first; both are taken off before giving up.
     */
    public static function decodeJson(?string $content): mixed
    {
        $content = trim($content ?? '');
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $content = trim((string) preg_replace('/^<think>.*?<\/think>/s', '', $content));
        $content = trim((string) preg_replace('/^```(?:json)?\s*(.*?)\s*```$/s', '$1', $content));
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        return $start !== false && $end !== false && $end > $start
            ? json_decode(substr($content, $start, $end - $start + 1), true)
            : null;
    }

    private function usable(mixed $p): bool
    {
        if (! is_array($p)
            || ! is_string($p['content'] ?? null) || trim($p['content']) === ''
            || ! (is_int($p['confidence'] ?? null) || is_float($p['confidence'] ?? null))) {
            return false;
        }

        foreach (['wing_slug', 'room_slug'] as $key) {
            if (array_key_exists($key, $p) && $p[$key] !== null && ! is_string($p[$key])) {
                return false;
            }
        }

        // A new wing has to be named.
        return empty($p['propose_new_wing']) || trim((string) ($p['wing_slug'] ?? '')) !== '';
    }
}
