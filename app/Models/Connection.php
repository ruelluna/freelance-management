<?php

namespace App\Models;

use App\Enums\Provider;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property Provider $provider
 * @property string $name
 * @property string $token
 * @property string|null $webhook_secret
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, ConnectedSource> $sources
 * @property-read Collection<int, Issue> $issues
 */
#[Fillable(['team_id', 'provider', 'name', 'token', 'webhook_secret', 'settings', 'last_synced_at'])]
#[Hidden(['token', 'webhook_secret'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<ConnectedSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ConnectedSource::class);
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
