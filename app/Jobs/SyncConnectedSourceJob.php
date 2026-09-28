<?php

namespace App\Jobs;

use App\Actions\Issues\RefreshIssueFromRemote;
use App\Models\ConnectedSource;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncConnectedSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [1, 5, 10];

    public int $uniqueFor = 600;

    public function __construct(public string $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(IssueProviderFactory $providers, RefreshIssueFromRemote $refresh): void
    {
        $source = ConnectedSource::query()->with('connection')->findOrFail($this->sourceId);
        $provider = $providers->make($source->connection);

        $issues = $provider->listIssues($source);

        foreach ($issues as $remote) {
            $refresh->syncRemote($source, $remote);
        }

        $source->update(['last_synced_at' => now()]);
        $source->connection->update(['last_synced_at' => now()]);

        Log::info('Connected source synced', [
            'source_id' => $source->id,
            'issues' => $issues->count(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Connected source sync failed', [
            'source_id' => $this->sourceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
