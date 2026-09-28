<?php

namespace App\Services\Integrations;

use App\Contracts\IssueProvider;
use App\Enums\Provider;
use App\Models\Connection;

class IssueProviderFactory
{
    public function make(Connection $connection): IssueProvider
    {
        return match ($connection->provider) {
            Provider::Github => app(GithubIssueProvider::class),
            Provider::Todoist => app(TodoistIssueProvider::class),
            Provider::Superhuman => app(SuperhumanIssueProvider::class),
        };
    }
}
