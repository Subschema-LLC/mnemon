<?php

namespace App\Support;

use App\Models\McpClientRestriction;
use App\Models\McpTokenRestriction;
use App\Models\Wing;

/**
 * The wings an access token may reach, for every surface that takes one: the
 * MCP tools (through RequiresWingAccess) and the HTTP upload endpoint.
 */
final class WingRestrictions
{
    /**
     * The restriction governing a token, or null when it may reach every wing.
     *
     * Client rows are preferred: a device's permitted wings belong to the
     * device, and a refreshed token inherits them by construction. Token rows
     * remain as a fallback for instances that consented before restrictions
     * moved off the token. Absence still means unrestricted, which is how
     * personal access tokens reach every wing.
     */
    public static function forToken(mixed $token): McpClientRestriction|McpTokenRestriction|null
    {
        $tokenId = $token?->id;
        if ($tokenId === null) {
            return null;
        }

        $clientId = $token->client_id ?? null;

        return ($clientId !== null ? McpClientRestriction::find((string) $clientId) : null)
            ?? McpTokenRestriction::find($tokenId);
    }

    /**
     * The wing a project files into for a caller holding these patterns, or
     * null when the caller cannot file there. Aliases count; a restricted
     * caller never creates a wing (see ProjectWings).
     *
     * @param  array<string>|null  $patterns  null = unrestricted
     */
    public static function projectWing(string $project, ?array $patterns): ?string
    {
        $ids = Wing::readableIds($patterns);
        $wings = Wing::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get()
            ->all();

        return ProjectWings::wingSlugForProject($project, $wings, $patterns);
    }
}
