<?php

namespace App\Data\Integrations;

use App\Enums\IssueStatus;
use Carbon\CarbonInterface;

readonly class RemoteIssue
{
    /**
     * @param  array<int, array{name: string, color: string}>  $labels
     * @param  array<int, string>  $assigneeLogins
     */
    public function __construct(
        public string $externalId,
        public ?int $number,
        public string $title,
        public ?string $body,
        public IssueStatus $status,
        public ?string $externalUrl,
        public ?CarbonInterface $externalUpdatedAt,
        public array $labels = [],
        public array $assigneeLogins = [],
    ) {}
}
