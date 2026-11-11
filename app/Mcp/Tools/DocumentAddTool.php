<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Concerns\ResolvesProjectWing;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Wing;
use App\Services\Documents\DocumentIngestService;
use App\Services\Documents\DocumentRejected;
use App\Services\Documents\DocumentType;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Upload a file into memory: docx, pptx, pdf, csv, png, jpg, webp or svg, sent as base64. Its text is extracted and stored as drawers, and the original kept for download; an image is described by a vision model, which is a paid call. Only for files. To store text you already have, use drawer_add.')]
class DocumentAddTool extends Tool
{
    use RequiresScope, RequiresWingAccess, ResolvesAgentSource, ResolvesProjectWing;

    protected string $name = 'document_add';

    public function __construct(protected DocumentIngestService $documents) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $maxMb = max(1, min(
            (int) config('mnemon.documents.mcp_max_mb', 10),
            (int) config('mnemon.documents.max_mb', 25),
        ));
        // Base64 is 4 characters per 3 bytes, plus padding and stray line breaks.
        $maxEncoded = (int) ceil($maxMb * 1024 * 1024 / 3) * 4 + 1024;

        $params = $request->validate([
            'file_name' => 'required|string|max:255',
            'content_base64' => "required|string|max:{$maxEncoded}",
            'wing' => 'required_without:project|prohibits:project|nullable|string',
            'project' => 'required_without:wing|prohibits:wing|nullable|string|max:100',
            'room' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:5000',
            'occurred_at' => 'nullable|date',
        ], [
            'content_base64.max' => "The file is larger than {$maxMb} MB, the most document_add accepts. Upload larger files in the admin panel or with the upload script.",
        ]);

        if (isset($params['project'])) {
            $wing = $this->wingForProject($request, $params['project']);
            if ($wing instanceof Response) {
                return $wing;
            }
        } else {
            $wing = Wing::slugify($params['wing']);
            if ($wing === '') {
                return Response::error('Parameter "wing" must contain at least one letter or digit.');
            }
            if ($err = $this->requireWingAccess($request, $wing)) {
                return $err;
            }
        }

        $audit = [
            'file_name' => $params['file_name'],
            'wing' => $wing,
            'room' => $params['room'] ?? null,
            'note' => isset($params['note']) ? mb_substr($params['note'], 0, 200) : null,
        ];

        $bytes = base64_decode(preg_replace('/\s+/', '', $params['content_base64']) ?? '', true);
        if ($bytes === false || $bytes === '') {
            return $this->refuse($request, $audit, 'content_base64 is not valid base64.');
        }
        if (strlen($bytes) > $maxMb * 1024 * 1024) {
            return $this->refuse($request, $audit, "The file is larger than {$maxMb} MB, the most document_add accepts.");
        }
        $audit['size'] = strlen($bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'mnemon-upload-');
        try {
            file_put_contents($tmp, $bytes);
            unset($bytes);

            $result = $this->documents->ingest(
                path: $tmp,
                originalName: $params['file_name'],
                wingSlug: $wing,
                roomSlug: $params['room'] ?? null,
                source: $this->agentSource($request, null),
                note: $params['note'] ?? null,
                occurredAt: isset($params['occurred_at']) ? Carbon::parse($params['occurred_at']) : null,
            );
        } catch (DocumentRejected $e) {
            return $this->refuse($request, $audit, $e->getMessage());
        } finally {
            @unlink($tmp);
        }

        $out = $result->toArray();
        BrainSessionLogger::log($request, 'document_add', $audit + ['document_id' => $out['id'], 'status' => $out['status']], $out['chunks']);

        return Response::structured($out);
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function refuse(Request $request, array $audit, string $reason): Response
    {
        BrainSessionLogger::logFailure($request, 'document_add', $audit, $reason);

        return Response::error($reason);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'file_name' => $s->string()->required()->description('The file name with its extension, e.g. "q3-report.pdf". Accepted: '.implode(', ', DocumentType::extensions()).'.'),
            'content_base64' => $s->string()->required()->description('The file\'s bytes, base64-encoded. At most MNEMON_DOCUMENT_MCP_MAX_MB (10 MB by default) once decoded.'),
            'wing' => $s->string()->description('Wing slug (auto-created if missing). Give exactly one of wing or project.'),
            'project' => $s->string()->description('Project name; files into that project\'s wing, the same wing drawer_add and session digests use. Give exactly one of wing or project.'),
            'room' => $s->string()->description('Room slug within the wing; defaults to "documents".'),
            'note' => $s->string()->description('Optional context stored with the document and used when describing an image, e.g. what it is and why it matters.'),
            'occurred_at' => $s->string()->description('Optional ISO-8601 time the document is from; a later time is clamped to now.'),
        ];
    }
}
