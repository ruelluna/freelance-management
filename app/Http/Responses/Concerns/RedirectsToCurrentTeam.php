<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function authenticatedHomePath(Request $request): string
    {
        $user = $request->user();
        $team = $this->currentTeam($request);

        $slug = $user->portalSlug($team);

        URL::defaults([
            'current_team' => $slug,
            'team' => $slug,
        ]);

        if ($user->isTeamClient($team)) {
            return "/{$slug}/dashboard";
        }

        return '/dashboard';
    }

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->personalTeam();

        abort_if(! $team, 403);

        return $team;
    }
}
