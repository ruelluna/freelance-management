<?php

namespace App\Data\Integrations;

readonly class IssueUpdate
{
    /**
     * @param  array<int, string>|null  $labelNames
     * @param  array<int, string>|null  $assigneeLogins
     */
    public function __construct(
        public ?string $status = null,
        public ?array $labelNames = null,
        public ?array $assigneeLogins = null,
        public ?string $title = null,
        public ?string $body = null,
    ) {}
}
