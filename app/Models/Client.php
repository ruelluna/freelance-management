<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueClientSlugs;
use App\Enums\ClientStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $name
 * @property string $slug
 * @property string|null $contact_email
 * @property ClientStatus $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Project> $projects
 */
#[Fillable(['team_id', 'name', 'slug', 'contact_email', 'status', 'notes'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use GeneratesUniqueClientSlugs, HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Client $client) {
            if (empty($client->slug)) {
                $client->slug = static::generateUniqueClientSlug($client->team_id, $client->name);
            }
        });

        static::updating(function (Client $client) {
            if ($client->isDirty('name')) {
                $client->slug = static::generateUniqueClientSlug(
                    $client->team_id,
                    $client->name,
                    $client->id,
                );
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')->withTimestamps();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
        ];
    }
}
