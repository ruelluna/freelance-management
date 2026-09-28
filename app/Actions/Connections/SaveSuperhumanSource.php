<?php

namespace App\Actions\Connections;

use App\Jobs\SyncConnectedSourceJob;
use App\Models\ConnectedSource;
use App\Models\Connection;
use App\Services\Integrations\SuperhumanIssueProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaveSuperhumanSource
{
    public function handle(
        Connection $connection,
        string $docId,
        string $pageId,
        string $pageName,
        string $boardId,
        string $boardName,
        ?string $selectedDocId = null,
    ): ConnectedSource {
        $connection->loadMissing('user');

        $emails = array_values(array_unique(array_filter([
            strtolower((string) ($connection->settings['owner_email'] ?? '')),
            strtolower((string) ($connection->user?->email ?? '')),
        ], fn (string $email): bool => $email !== '')));

        $source = DB::transaction(function () use (
            $connection,
            $docId,
            $pageId,
            $pageName,
            $boardId,
            $boardName,
            $emails,
            $selectedDocId,
        ): ConnectedSource {
            $externalId = $docId.'/'.$boardId;

            $connection->sources()
                ->where('external_id', '!=', $externalId)
                ->get()
                ->each(fn (ConnectedSource $existing) => $existing->delete());

            $settings = [
                'page_id' => $pageId,
                'page_name' => trim($pageName) !== '' ? trim($pageName) : $pageId,
                'selected_doc_id' => $selectedDocId !== null && $selectedDocId !== '' ? $selectedDocId : $docId,
                'match_emails' => $emails,
                'closed_statuses' => SuperhumanIssueProvider::DEFAULT_CLOSED_STATUSES,
            ];

            $source = $connection->sources()->where('external_id', $externalId)->first();
            $name = trim($boardName) !== '' ? trim($boardName) : $boardId;

            if (trim($pageName) !== '') {
                $name = trim($pageName).' / '.$name;
            }

            if ($source === null) {
                return $connection->sources()->create([
                    'team_id' => $connection->team_id,
                    'external_id' => $externalId,
                    'name' => $name,
                    'settings' => $settings,
                ]);
            }

            $previous = $source->settings ?? [];

            if (is_string($previous['sync_token'] ?? null) && $previous['sync_token'] !== '') {
                $settings['sync_token'] = $previous['sync_token'];
            }

            $source->update([
                'name' => $name,
                'settings' => $settings,
            ]);

            return $source;
        });

        SyncConnectedSourceJob::dispatch($source->id);

        Log::info('Superhuman Docs board saved', [
            'connection_id' => $connection->id,
            'source_id' => $source->id,
        ]);

        return $source;
    }
}
