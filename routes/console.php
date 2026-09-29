<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('issues:sync')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->description('Sync connected issue sources');

Schedule::command('editor-images:prune')
    ->daily()
    ->description('Delete unattached editor images older than a day');
