<?php

namespace App\Mcp\Concerns;

use App\Models\Wing;
use App\Support\WingRestrictions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * For tools that file by project instead of by wing. Used with
 * RequiresWingAccess.
 */
trait ResolvesProjectWing
{
    /**
     * The wing a project files into, or the refusal to return. A project whose
     * wing the token cannot reach is refused exactly as a missing wing is.
     */
    protected function wingForProject(Request $request, string $project): string|Response
    {
        $name = Wing::slugify($project);
        if ($name === '') {
            return Response::error('Parameter "project" must contain at least one letter or digit.');
        }

        $slug = WingRestrictions::projectWing($project, $this->wingPatternsFor($request));
        if ($slug !== null) {
            return $slug;
        }

        $missing = "project-{$name}";

        return $this->requireWingAccess($request, $missing)
            ?? Response::error("Token does not have access to wing: {$missing}");
    }
}
