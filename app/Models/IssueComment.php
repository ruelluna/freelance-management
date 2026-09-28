<?php

namespace App\Models;

use App\Enums\CommentOrigin;
use Database\Factories\IssueCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $issue_id
 * @property int|null $user_id
 * @property string|null $external_id
 * @property string $body
 * @property string|null $body_html
 * @property string $author_name
 * @property CommentOrigin $origin
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Issue $issue
 * @property-read User|null $user
 */
#[Fillable(['issue_id', 'user_id', 'external_id', 'body', 'body_html', 'author_name', 'origin', 'synced_at'])]
class IssueComment extends Model
{
    /** @use HasFactory<IssueCommentFactory> */
    use HasFactory, HasUuids;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => CommentOrigin::class,
            'synced_at' => 'datetime',
        ];
    }
}
