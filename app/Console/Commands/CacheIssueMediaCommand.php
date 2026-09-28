<?php

namespace App\Console\Commands;

use App\Actions\Issues\CacheIssueMediaFromHtml;
use App\Enums\Provider;
use App\Models\Issue;
use App\Services\Integrations\GithubIssueProvider;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Console\Command;

class CacheIssueMediaCommand extends Command
{
    protected $signature = 'issues:cache-media {--issue= : Cache media for a specific issue UUID}';

    protected $description = 'Fetch GitHub HTML bodies and cache issue attachment media locally';

    public function handle(
        IssueProviderFactory $providers,
        CacheIssueMediaFromHtml $cacheMedia,
    ): int {
        $issues = Issue::query()
            ->with(['connectedSource.connection', 'comments'])
            ->whereHas('connection', fn ($query) => $query->where('provider', Provider::Github))
            ->when(
                $this->option('issue'),
                fn ($query) => $query->whereKey($this->option('issue')),
                fn ($query) => $query->whereNull('body_html'),
            )
            ->get();

        if ($issues->isEmpty()) {
            $this->info('No GitHub issues need media caching.');

            return self::SUCCESS;
        }

        $cached = 0;

        foreach ($issues as $issue) {
            $source = $issue->connectedSource;
            $provider = $providers->make($source->connection);

            if (! $provider instanceof GithubIssueProvider || $issue->number === null) {
                continue;
            }

            $html = $provider->fetchIssueBodyHtml($source, $issue->number);
            $bodyHtml = $cacheMedia->forIssue($issue, $html, $issue->body);

            if ($bodyHtml !== null) {
                $issue->update(['body_html' => $bodyHtml]);
                $cached++;
            }

            foreach ($issue->comments()->where('origin', 'remote')->whereNull('body_html')->get() as $comment) {
                if ($comment->external_id === null) {
                    continue;
                }

                $commentHtml = $provider->fetchCommentBodyHtml($source, (int) $comment->external_id);
                $commentBodyHtml = $cacheMedia->forComment($issue, $comment, $commentHtml);

                if ($commentBodyHtml !== null) {
                    $comment->update(['body_html' => $commentBodyHtml]);
                }
            }
        }

        $this->info("Cached media for {$cached} issue(s).");

        return self::SUCCESS;
    }
}
