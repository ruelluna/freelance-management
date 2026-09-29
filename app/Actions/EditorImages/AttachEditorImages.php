<?php

namespace App\Actions\EditorImages;

use App\Models\EditorImage;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AttachEditorImages
{
    public function handle(User $user, Model $parent, ?string $markdown): ?string
    {
        if ($markdown === null || trim($markdown) === '') {
            $this->deleteUnused($parent);

            return $markdown;
        }

        $ids = $this->ids($markdown);

        $images = EditorImage::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (EditorImage $image): string => strtolower($image->id));

        $kept = [];

        foreach ($ids as $id) {
            $image = $images->get($id);

            if (! $image instanceof EditorImage || ! $this->canEmbed($user, $parent, $image)) {
                $markdown = $this->strip($markdown, $id);

                continue;
            }

            if ($image->imageable_id === null) {
                $image->imageable()->associate($parent);
                $image->save();
            }

            $kept[] = $image->id;
        }

        $this->deleteUnused($parent, $kept);

        return $markdown;
    }

    /**
     * @return array<int, string>
     */
    protected function ids(string $markdown): array
    {
        preg_match_all(
            '/editor-images\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
            $markdown,
            $matches,
        );

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }

    protected function canEmbed(User $user, Model $parent, EditorImage $image): bool
    {
        if ((int) $image->team_id !== (int) $this->teamId($parent)) {
            return false;
        }

        if ($image->imageable_id === null) {
            return $image->user_id === $user->id;
        }

        return $image->imageable_type === $parent->getMorphClass()
            && (string) $image->imageable_id === (string) $parent->getKey();
    }

    protected function teamId(Model $parent): int
    {
        if ($parent instanceof Issue || $parent instanceof Project) {
            return (int) $parent->team_id;
        }

        if ($parent instanceof IssueComment) {
            $parent->loadMissing('issue');

            return (int) $parent->issue->team_id;
        }

        throw new InvalidArgumentException('Editor images can only be attached to an issue, project, or comment.');
    }

    protected function strip(string $markdown, string $id): string
    {
        $id = preg_quote($id, '/');

        $markdown = (string) preg_replace('/!\[[^\]]*\]\([^)]*editor-images\/'.$id.'[^)]*\)/i', '', $markdown);
        $markdown = (string) preg_replace('/<img\b[^>]*editor-images\/'.$id.'[^>]*>/i', '', $markdown);
        $markdown = (string) preg_replace('/https?:\/\/\S*editor-images\/'.$id.'\b\S*/i', '', $markdown);

        return (string) preg_replace('/(?<!\w)\/\S*editor-images\/'.$id.'\b\S*/i', '', $markdown);
    }

    /**
     * @param  array<int, string>  $keptIds
     */
    protected function deleteUnused(Model $parent, array $keptIds = []): void
    {
        if (! $parent instanceof Issue && ! $parent instanceof Project) {
            return;
        }

        $query = EditorImage::query()
            ->where('imageable_type', $parent->getMorphClass())
            ->where('imageable_id', $parent->getKey());

        if ($keptIds !== []) {
            $query->whereNotIn('id', $keptIds);
        }

        $query->get()->each(function (EditorImage $image): void {
            $image->purge();
        });
    }
}
