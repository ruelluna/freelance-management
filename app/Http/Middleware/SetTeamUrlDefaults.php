<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    /**
     * Set the default URL parameters for team-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $currentTeam = $user?->currentTeam;

        if ($user !== null && $currentTeam !== null) {
            $slug = $user->portalSlug($currentTeam);

            URL::defaults([
                'current_team' => $slug,
                'team' => $slug,
            ]);
        }

        return $next($request);
    }
}
