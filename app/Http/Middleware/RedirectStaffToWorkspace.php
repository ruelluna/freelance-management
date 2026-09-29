<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectStaffToWorkspace
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $teamSlug = $request->route('current_team');

        if (! is_string($teamSlug) || $user === null || $request->routeIs('issues.media', 'editor-images.show', 'dashboard')) {
            return $next($request);
        }

        $team = $user->currentTeam;

        if ($team === null || $user->isTeamClient($team)) {
            return $next($request);
        }

        $prefix = $teamSlug.'/';
        $path = ltrim($request->path(), '/');

        if (! str_starts_with($path, $prefix)) {
            return $next($request);
        }

        $target = '/'.substr($path, strlen($prefix));
        $query = $request->getQueryString();

        return redirect($query === null ? $target : $target.'?'.$query);
    }
}
