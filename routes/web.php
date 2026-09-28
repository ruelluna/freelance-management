<?php

use App\Http\Controllers\IssueMediaController;
use App\Http\Controllers\Webhooks\GithubWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/github/{connection}', GithubWebhookController::class)
    ->name('webhooks.github');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
        Route::livewire('issues', 'pages::issues.index')->name('issues.index');
        Route::livewire('issues/{issue}', 'pages::issues.show')->name('issues.show');
        Route::get('issues/{issue}/media/{asset}', IssueMediaController::class)->name('issues.media');
        Route::livewire('labels', 'pages::labels.index')->name('labels.index');
        Route::livewire('connections', 'pages::connections.index')->name('connections.index');
    });

require __DIR__.'/settings.php';
