<?php

namespace App\Concerns;

use App\Models\EditorImage;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

trait HasEditorImages
{
    /**
     * @return MorphMany<EditorImage, $this>
     */
    public function editorImages(): MorphMany
    {
        return $this->morphMany(EditorImage::class, 'imageable');
    }

    protected static function bootHasEditorImages(): void
    {
        static::deleting(function (Model $model): void {
            if (! $model instanceof Issue && ! $model instanceof Project && ! $model instanceof IssueComment) {
                return;
            }

            $images = $model->editorImages()->get();

            if ($model instanceof Issue) {
                $commentIds = $model->comments()->pluck('id');

                if ($commentIds->isNotEmpty()) {
                    $images = $images->merge(
                        EditorImage::query()
                            ->where('imageable_type', (new IssueComment)->getMorphClass())
                            ->whereIn('imageable_id', $commentIds)
                            ->get(),
                    );
                }
            }

            if ($images->isEmpty()) {
                return;
            }

            DB::afterCommit(function () use ($images): void {
                $images->each(function (EditorImage $image): void {
                    $image->purge();
                });
            });
        });
    }
}
