<?php

namespace App\Console\Commands;

use App\Models\EditorImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('editor-images:prune')]
#[Description('Delete unattached editor images older than a day')]
class PruneEditorImagesCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        EditorImage::query()
            ->whereNull('imageable_id')
            ->where('created_at', '<', now()->subDay())
            ->eachById(function (EditorImage $image) use (&$count): void {
                $image->purge();
                $count++;
            });

        $this->info("Deleted {$count} unattached editor image(s).");

        return self::SUCCESS;
    }
}
