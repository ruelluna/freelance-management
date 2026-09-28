<?php

namespace App\Models;

use Database\Factories\ConnectedSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $connection_id
 * @property int $team_id
 * @property string $external_id
 * @property string $name
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Connection $connection
 * @property-read Team $team
 * @property-read Collection<int, Issue> $issues
 * @property-read Collection<int, Project> $projects
 */
#[Fillable(['connection_id', 'team_id', 'external_id', 'name', 'settings', 'last_synced_at'])]
class ConnectedSource extends Model
{
    /** @use HasFactory<ConnectedSourceFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
