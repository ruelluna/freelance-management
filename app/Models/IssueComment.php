<?php

namespace App\Models;

use App\Concerns\HasEditorImages;
use App\Enums\CommentAudience;
use App\Enums\CommentOrigin;
use Database\Factories\IssueCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $issue_id
 * @property string|null $parent_id
 * @property int|null $user_id
 * @property string|null $external_id
 * @property string $body
 * @property string|null $body_html
 * @property string $author_name
 * @property CommentOrigin $origin
 * @property CommentAudience $audience
 * @property Carbon|null $shared_at
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Issue $issue
 * @property-read IssueComment|null $parent
 * @property-read Collection<int, IssueComment> $replies
 * @property-read User|null $user
 * @property-read Collection<int, EditorImage> $editorImages
 */
#[Fillable(['issue_id', 'parent_id', 'user_id', 'external_id', 'body', 'body_html', 'author_name', 'origin', 'audience', 'shared_at', 'synced_at'])]
class IssueComment extends Model
{
    /** @use HasFactory<IssueCommentFactory> */
    use HasEditorImages, HasFactory, HasUuids;

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<IssueComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<IssueComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    /**
     * Comments the user is allowed to read. Owners see every comment. Clients see the client thread.
     * Admins and employees see team notes and client comments that have been shared.
     * A reply stays hidden unless its parent comment is visible too.
     *
     * @param  Builder<IssueComment>  $query
     * @return Builder<IssueComment>
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user, Team $team): Builder
    {
        if ($user->ownsTeam($team)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        $this->constrainAudience($query, $user, $team, $table);

        return $query->whereIn($table.'.id', $this->reachableCommentIds($query, $table));
    }

    /**
     * @param  Builder<IssueComment>  $query
     * @return array<int, string>
     */
    protected function reachableCommentIds(Builder $query, string $table): array
    {
        $rows = (clone $query)
            ->select($table.'.id', $table.'.parent_id')
            ->reorder()
            ->get();

        $parents = $rows->pluck('parent_id', 'id');
        $reachable = [];

        $isReachable = function (string $id) use (&$isReachable, &$reachable, $parents): bool {
            if (array_key_exists($id, $reachable)) {
                return $reachable[$id];
            }

            $reachable[$id] = false;

            if (! $parents->has($id)) {
                return false;
            }

            $parentId = $parents->get($id);

            if ($parentId === null) {
                return $reachable[$id] = true;
            }

            return $reachable[$id] = $isReachable($parentId);
        };

        return $rows
            ->pluck('id')
            ->filter(fn (string $id): bool => $isReachable($id))
            ->values()
            ->all();
    }

    protected function constrainAudience(Builder|QueryBuilder $query, User $user, Team $team, string $table): void
    {
        if ($user->isTeamClient($team)) {
            $query->where($table.'.audience', CommentAudience::Client);

            return;
        }

        $query->where(function (Builder|QueryBuilder $comments) use ($table): void {
            $comments->where($table.'.audience', CommentAudience::Internal)
                ->orWhere(function (Builder|QueryBuilder $shared) use ($table): void {
                    $shared->where($table.'.audience', CommentAudience::Client)
                        ->whereNotNull($table.'.shared_at');
                });
        });
    }

    public function isSharedWithTeam(): bool
    {
        return $this->shared_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => CommentOrigin::class,
            'audience' => CommentAudience::class,
            'shared_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
