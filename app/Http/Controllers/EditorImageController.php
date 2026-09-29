<?php

namespace App\Http\Controllers;

use App\Models\EditorImage;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EditorImageController extends Controller
{
    public function __invoke(Team $current_team, EditorImage $editorImage): Response
    {
        $user = auth()->user();

        abort_unless((int) $editorImage->team_id === (int) $current_team->id, 404);
        abort_unless(str_starts_with($editorImage->path, 'editor-images/') && ! str_contains($editorImage->path, '..'), 404);
        abort_unless(in_array($editorImage->mime_type, EditorImage::MIMES, true), 404);
        abort_unless($user !== null && $editorImage->canBeViewedBy($user), 404);
        abort_unless(Storage::disk($editorImage->disk)->exists($editorImage->path), 404);

        return response()->file(Storage::disk($editorImage->disk)->path($editorImage->path), [
            'Content-Type' => $editorImage->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
