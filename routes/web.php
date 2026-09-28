<?php

use App\Http\Controllers\IssueMediaController;
use App\Http\Controllers\Webhooks\GithubWebhookController;
use App\Http\Middleware\EnsureStaffWorkspace;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\RedirectStaffToWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if ($user === null) {
        return redirect()->route('login');
    }

    return redirect()->route($user->homeRoute());
})->name('home');

Route::post('webhooks/github/{connection}', GithubWebhookController::class)
    ->name('webhooks.github');

Route::livewire('dashboard', 'pages::workspace')
    ->middleware(['auth', 'verified'])
    ->name('workspace');

Route::middleware(['auth', 'verified', EnsureStaffWorkspace::class])->group(function () {
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}/edit', 'pages::projects.edit')->name('projects.edit');
    Route::livewire('projects/{project}/integrations', 'pages::projects.integrations')->name('projects.integrations');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('issues', 'pages::issues.index')->name('issues.index');
    Route::livewire('issues/{issue}/edit', 'pages::issues.edit')->name('issues.edit');
    Route::livewire('issues/{issue}', 'pages::issues.show')->name('issues.show');
    Route::livewire('users', 'pages::users.index')->name('users.index');
    Route::livewire('users/{user}', 'pages::users.show')->name('users.show');
    Route::livewire('clients', 'pages::clients.index')->name('clients.index');
    Route::livewire('clients/{client}', 'pages::clients.show')->name('clients.show');
    Route::livewire('labels', 'pages::labels.index')->name('labels.index');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, RedirectStaffToWorkspace::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('clients', 'pages::clients.index')->name('client.clients.index');
        Route::livewire('clients/{client}', 'pages::clients.show')->name('client.clients.show');
        Route::livewire('projects', 'pages::projects.index')->name('client.projects.index');
        Route::livewire('projects/{project}/edit', 'pages::projects.edit')->name('client.projects.edit');
        Route::livewire('projects/{project}/integrations', 'pages::projects.integrations')->name('client.projects.integrations');
        Route::livewire('projects/{project}', 'pages::projects.show')->name('client.projects.show');
        Route::livewire('issues', 'pages::issues.index')->name('client.issues.index');
        Route::livewire('issues/{issue}/edit', 'pages::issues.edit')->name('client.issues.edit');
        Route::livewire('issues/{issue}', 'pages::issues.show')->name('client.issues.show');
        Route::livewire('users', 'pages::users.index')->name('client.users.index');
        Route::get('issues/{issue}/media/{asset}', IssueMediaController::class)->name('issues.media');
        Route::livewire('labels', 'pages::labels.index')->name('client.labels.index');
    });

require __DIR__.'/settings.php';
