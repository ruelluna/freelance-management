<?php

namespace App\Jobs;

use App\Actions\Issues\RefreshIssueFromRemote;
use App\Data\Integrations\RemoteIssue;
use App\Enums\IssueStatus;
use App\Models\ConnectedSource;
use App\Models\Issue;
use App\Services\Integrations\IssueProviderFactory;
use App\Services\Integrations\TodoistIssueProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
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

        if ($provider instanceof TodoistIssueProvider) {
            $this->closeCompletedTodoistIssues($source, $provider);
            $this->markTodoistInitialImport($source, $provider, $issues);
        }

        $source->update(['last_synced_at' => now()]);
        $source->connection->update(['last_synced_at' => now()]);

        Log::info('Connected source synced', [
            'source_id' => $source->id,
            'issues' => $issues->count(),
        ]);
    }

    protected function closeCompletedTodoistIssues(ConnectedSource $source, TodoistIssueProvider $provider): void
    {
        $from = Date::parse((string) config('services.todoist.sync_from'))->startOfDay();
        $close = [];
        $drop = [];

        foreach ($provider->listCompletedTasks($source) as $task) {
            $id = (string) ($task['id'] ?? '');

            if ($id === '') {
                continue;
            }

            if (isset($task['added_at']) && Date::parse($task['added_at'])->lt($from)) {
                $drop[] = $id;

                continue;
            }

            $close[] = $id;
        }

        if ($close !== []) {
            Issue::query()
                ->where('connected_source_id', $source->id)
                ->whereIn('external_id', $close)
                ->update([
                    'status' => IssueStatus::Closed,
                    'last_synced_at' => now(),
                ]);
        }

        if ($drop !== []) {
            Issue::query()
                ->where('connected_source_id', $source->id)
                ->whereIn('external_id', $drop)
                ->delete();
        }
    }

    /**
     * @param  Collection<int, RemoteIssue>  $imported
     */
    protected function markTodoistInitialImport(ConnectedSource $source, TodoistIssueProvider $provider, Collection $imported): void
    {
        if (isset($source->settings['initial_import_at'])) {
            return;
        }

        $beforeCutoff = $provider->listExternalIdsCreatedBeforeCutoff($source);
        $keep = $imported->map(fn (RemoteIssue $remote): string => $remote->externalId)->all();

        Issue::query()
            ->where('connected_source_id', $source->id)
            ->where(function ($query) use ($beforeCutoff, $keep): void {
                $query->where(function ($open) use ($keep): void {
                    $open->where('status', IssueStatus::Open);

                    if ($keep !== []) {
                        $open->whereNotIn('external_id', $keep);
                    }
                });

                if ($beforeCutoff !== []) {
                    $query->orWhereIn('external_id', $beforeCutoff);
                }
            })
            ->delete();

        $settings = $source->settings ?? [];
        $settings['initial_import_at'] = now()->toIso8601String();
        $source->update(['settings' => $settings]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Connected source sync failed', [
            'source_id' => $this->sourceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
