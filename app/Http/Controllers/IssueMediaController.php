<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Team;
use App\Services\Integrations\GithubIssueMediaCache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class IssueMediaController extends Controller
{
    public function __invoke(
        Team $current_team,
        Issue $issue,
        string $asset,
        GithubIssueMediaCache $mediaCache,
    ): Response {
        abort_unless($issue->team_id === $current_team->id, 404);
        abort_unless(
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $asset) === 1,
            404,
        );

        Gate::authorize('view', $issue);

        $path = $mediaCache->storedMediaPath($issue, strtolower($asset));

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $mediaCache->storedMediaMimeType($path),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
