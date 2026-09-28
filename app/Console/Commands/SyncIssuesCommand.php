<?php

namespace App\Console\Commands;

use App\Jobs\SyncConnectedSourceJob;
use App\Models\ConnectedSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('issues:sync')]
#[Description('Queue a sync for every connected issue source')]
class SyncIssuesCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        ConnectedSource::query()->each(function (ConnectedSource $source) use (&$count): void {
            SyncConnectedSourceJob::dispatch($source->id);
            $count++;
        });

        $this->info("Queued sync for {$count} connected sources.");

        return self::SUCCESS;
    }
}
