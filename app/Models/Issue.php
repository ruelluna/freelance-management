<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property string|null $project_id
 * @property int|null $created_by
 * @property string|null $connection_id
 * @property string|null $connected_source_id
 * @property string|null $external_id
 * @property int|null $number
 * @property string $title
 * @property string|null $body
 * @property string|null $body_html
 * @property IssueStatus $status
 * @property string|null $external_url
 * @property Carbon|null $external_updated_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read User|null $creator
 * @property-read Connection|null $connection
 * @property-read ConnectedSource|null $connectedSource
 * @property-read Collection<int, IssueComment> $comments
 * @property-read Collection<int, Label> $labels
 * @property-read Collection<int, User> $assignees
 */
#[Fillable([
    'team_id',
    'project_id',
    'created_by',
    'connection_id',
    'connected_source_id',
    'external_id',
    'number',
    'title',
    'body',
    'body_html',
    'status',
    'external_url',
    'external_updated_at',
    'last_synced_at',
])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<ConnectedSource, $this>
     */
    public function connectedSource(): BelongsTo
    {
        return $this->belongsTo(ConnectedSource::class);
    }

    /**
     * @return HasMany<IssueComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->oldest();
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_assignee')->withTimestamps();
    }

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query->where('status', IssueStatus::Open);
    }

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    #[Scope]
    protected function closed(Builder $query): Builder
    {
        return $query->where('status', IssueStatus::Closed);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignees()->where('users.id', $user->id)->exists();
    }

    public function isLinkedToSource(): bool
    {
        return $this->connection_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
