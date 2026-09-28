<?php

namespace App\Data;

use App\Models\Issue;

readonly class CreatedIssue
{
    public function __construct(
        public Issue $issue,
        public ?string $publishError = null,
    ) {}
}
