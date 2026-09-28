<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffWorkspace
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $team = $user?->currentTeam ?? $user?->personalTeam();

        abort_if($user === null || $team === null || ! $user->belongsToTeam($team), 403);

        if ($user->isTeamClient($team)) {
            return redirect($this->teamPath($user->portalSlug($team) ?? $team->slug, $request));
        }

        return $next($request);
    }

    private function teamPath(string $slug, Request $request): string
    {
        $path = '/'.$slug.'/'.ltrim($request->path(), '/');
        $query = $request->getQueryString();

        return $query === null ? $path : $path.'?'.$query;
    }
}
