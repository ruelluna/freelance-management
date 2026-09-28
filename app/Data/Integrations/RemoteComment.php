<?php

namespace App\Data\Integrations;

use Carbon\CarbonInterface;

readonly class RemoteComment
{
    public function __construct(
        public string $externalId,
        public string $body,
        public string $authorName,
        public ?CarbonInterface $createdAt = null,
    ) {}
}
