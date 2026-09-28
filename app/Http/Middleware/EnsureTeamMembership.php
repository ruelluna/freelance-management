<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $team] = [$request->user(), $this->team($request)];

        abort_if(! $user || ! $team || ! $user->belongsToTeam($team), 403);

        $segment = $request->route('current_team');

        if (is_string($segment) && $user->isTeamClient($team)) {
            $portal = $user->portalSlug($team);

            if ($portal !== null && $segment !== $portal) {
                return redirect($this->clientPath($segment, $portal, $request));
            }
        }

        $this->ensureTeamMemberHasRequiredRole($user, $team, $minimumRole);

        if ($request->route('current_team') && ! $user->isCurrentTeam($team)) {
            $user->switchTeam($team);
        }

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTeamMemberHasRequiredRole(User $user, Team $team, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->teamRole($team);

        $requiredRole = TeamRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the team associated with the request.
     */
    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if ($team instanceof Team) {
            return $team;
        }

        if (! is_string($team)) {
            return null;
        }

        $matchedTeam = Team::query()->where('slug', $team)->first();

        if ($matchedTeam !== null) {
            return $matchedTeam;
        }

        return $request->user()
            ?->clients()
            ->where('clients.slug', $team)
            ->first()
            ?->team;
    }

    private function clientPath(string $from, string $to, Request $request): string
    {
        $path = preg_replace(
            '#^'.preg_quote($from, '#').'(?=/|$)#',
            $to,
            ltrim($request->path(), '/'),
            1,
        );
        $query = $request->getQueryString();

        return '/'.$path.($query === null ? '' : '?'.$query);
    }
}
