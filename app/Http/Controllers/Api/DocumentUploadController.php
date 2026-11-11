<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Wing;
use App\Services\Documents\DocumentIngestService;
use App\Services\Documents\DocumentRejected;
use App\Support\WingRestrictions;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/documents: a multipart upload, for scripts and the plugin's
 * /mnemon:upload. Authenticated with the same OAuth tokens as /mcp, and bound
 * by the same wing restrictions.
 */
class DocumentUploadController extends Controller
{
    private const TOOL = 'document_upload';

    public function __invoke(Request $request, DocumentIngestService $documents): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token === null || ! $token->can('mcp:use')) {
            $reason = 'Missing required scope: mcp:use';
            BrainSessionLogger::logDenial($request, self::TOOL, [], $reason);

            return response()->json(['error' => $reason], 403);
        }

        $maxMb = max(1, (int) config('mnemon.documents.max_mb', 25));

        $params = $request->validate([
            'file' => ['required', 'file', 'max:'.($maxMb * 1024)],
            'wing' => 'required_without:project|prohibits:project|nullable|string|max:100',
            'project' => 'required_without:wing|prohibits:wing|nullable|string|max:100',
            'room' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:5000',
            'occurred_at' => 'nullable|date',
        ], [
            'file.max' => "The file is larger than {$maxMb} MB, the most Mnemon accepts (MNEMON_DOCUMENT_MAX_MB).",
            'file.uploaded' => 'The file did not arrive. It may be larger than the server allows (PHP upload_max_filesize and post_max_size).',
        ]);

        $file = $request->file('file');
        $restriction = WingRestrictions::forToken($token);

        if (isset($params['project'])) {
            $name = Wing::slugify($params['project']);
            if ($name === '') {
                return response()->json(['error' => 'The project must contain at least one letter or digit.'], 422);
            }
            // Null when a restricted token's project has no wing it can reach;
            // a restricted token never creates one.
            $resolved = WingRestrictions::projectWing($params['project'], $restriction?->wing_patterns);
            $wing = $resolved ?? "project-{$name}";
            $allowed = $resolved !== null;
        } else {
            $wing = Wing::slugify($params['wing']);
            if ($wing === '') {
                return response()->json(['error' => 'The wing must contain at least one letter or digit.'], 422);
            }
            $allowed = $restriction === null || $restriction->matches($wing);
        }

        $audit = [
            'file_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'wing' => $wing,
            'room' => $params['room'] ?? null,
            'note' => isset($params['note']) ? mb_substr($params['note'], 0, 200) : null,
        ];

        // The same refusal whether or not the wing exists: the caller named it.
        if (! $allowed) {
            $reason = "Token does not have access to wing: {$wing}";
            BrainSessionLogger::logDenial($request, self::TOOL, $audit, $reason);

            return response()->json(['error' => $reason], 403);
        }

        try {
            $result = $documents->ingest(
                path: $file->getRealPath(),
                originalName: $file->getClientOriginalName(),
                wingSlug: $wing,
                roomSlug: $params['room'] ?? null,
                source: $token->name ?? $token->client?->name ?? 'unknown-client',
                note: $params['note'] ?? null,
                occurredAt: isset($params['occurred_at']) ? Carbon::parse($params['occurred_at']) : null,
            );
        } catch (DocumentRejected $e) {
            BrainSessionLogger::logFailure($request, self::TOOL, $audit, $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 422);
        }

        $out = $result->toArray();
        BrainSessionLogger::log($request, self::TOOL, $audit + ['document_id' => $out['id'], 'status' => $out['status']], $out['chunks']);

        return response()->json($out, $result->created ? 201 : 200);
    }
}
