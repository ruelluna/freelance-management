<?php

namespace App\Models;

use Database\Factories\EditorImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property string|null $imageable_type
 * @property string|null $imageable_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $user
 * @property-read Model|null $imageable
 */
#[Fillable(['team_id', 'user_id', 'disk', 'path', 'mime_type', 'imageable_type', 'imageable_id'])]
class EditorImage extends Model
{
    /** @use HasFactory<EditorImageFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    public const array MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): string
    {
        $this->loadMissing('team');

        return route('editor-images.show', [
            'current_team' => $this->team->slug,
            'editorImage' => $this,
        ]);
    }

    public function canBeViewedBy(User $user): bool
    {
        $this->loadMissing('team');

        if (! $user->belongsToTeam($this->team)) {
            return false;
        }

        if ($this->imageable_id === null) {
            return (int) $this->user_id === (int) $user->id;
        }

        $parent = $this->imageable;

        if ($parent instanceof Issue || $parent instanceof Project) {
            return $user->can('view', $parent);
        }

        if ($parent instanceof IssueComment) {
            $parent->loadMissing('issue.team');

            return IssueComment::query()
                ->whereKey($parent->id)
                ->visibleTo($user, $parent->issue->team)
                ->exists();
        }

        return false;
    }

    public function deleteStoredFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }

    public function purge(): void
    {
        if (! $this->exists) {
            return;
        }

        $this->deleteStoredFile();
        $this->delete();
    }
}
