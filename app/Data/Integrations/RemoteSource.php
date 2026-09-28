<?php

namespace App\Data\Integrations;

readonly class RemoteSource
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public array $meta = [],
    ) {}
}
