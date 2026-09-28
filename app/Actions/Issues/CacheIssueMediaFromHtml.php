<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Services\Integrations\GithubIssueMediaCache;

class CacheIssueMediaFromHtml
{
    public function __construct(public GithubIssueMediaCache $mediaCache) {}

    public function forIssue(Issue $issue, ?string $html, ?string $markdownBody = null): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->mediaCache->cacheAndRewrite($issue, $html, $markdownBody);
    }

    public function forComment(Issue $issue, IssueComment $comment, ?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->mediaCache->cacheAndRewrite($issue, $html, $comment->body);
    }
}
