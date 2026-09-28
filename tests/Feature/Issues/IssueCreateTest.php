<?php

use App\Actions\Issues\RefreshIssueFromRemote;
use App\Jobs\SyncConnectedSourceJob;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\Integrations\IssueProviderFactory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a member can create a local task with a description and assignees', function () {
    Http::preventStrayRequests();

    ['team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create([
        'name' => 'Avery Member',
        'email' => 'member@example.com',
    ]));

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
        'connected_source_id' => null,
    ]);

    attachProjectMember($project, $member);

    $this->actingAs($member);

    Livewire::test('issues.task-form')
        ->set('projectId', $project->id)
        ->set('title', 'Write the proposal')
        ->set('description', 'Cover scope and timeline.')
        ->set('assigneeIds', [$member->id])
        ->set('publishToGithub', true)
        ->call('create')
        ->assertHasNoErrors();

    $task = Issue::query()->where('title', 'Write the proposal')->first();

    expect($task)
        ->body->toBe('Cover scope and timeline.')
        ->project_id->toBe($project->id)
        ->created_by->toBe($member->id)
        ->connection_id->toBeNull()
        ->external_id->toBeNull();

    expect($task->assignees()->pluck('users.id')->all())->toContain($member->id);
});

test('creating a task can also open a github issue on the project repo', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'team' => $team, 'source' => $source] = githubConnectionForOwner();

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
        'connected_source_id' => $source->id,
    ]);

    Http::fake([
        'https://api.github.com/repos/acme/api/issues' => Http::response(githubIssuePayload([
            'id' => 555,
            'number' => 20,
            'title' => 'Write the proposal',
            'body' => 'Cover scope and timeline.',
            'html_url' => 'https://github.com/acme/api/issues/20',
        ]), 201),
    ]);

    $this->actingAs($owner);

    Livewire::test('issues.task-form')
        ->set('projectId', $project->id)
        ->set('title', 'Write the proposal')
        ->set('description', 'Cover scope and timeline.')
        ->set('publishToGithub', true)
        ->call('create')
        ->assertHasNoErrors();

    $task = Issue::query()->where('title', 'Write the proposal')->first();

    expect($task)
        ->project_id->toBe($project->id)
        ->connected_source_id->toBe($source->id)
        ->external_id->toBe('555')
        ->number->toBe(20)
        ->external_url->toBe('https://github.com/acme/api/issues/20');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/acme/api/issues'
            && $request['title'] === 'Write the proposal'
            && $request['body'] === 'Cover scope and timeline.';
    });
});

test('the task creator can edit the title and description', function () {
    ['team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create(['email' => 'member@example.com']));

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Client site',
    ]);

    $otherProject = Project::factory()->create([
        'team_id' => $team->id,
        'name' => 'Follow-up',
    ]);

    attachProjectMember($project, $member);
    attachProjectMember($otherProject, $member);

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $member->id,
        'title' => 'Old title',
        'body' => 'Old description',
        'body_html' => '<p>Old description</p>',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::issues.show', ['issue' => $task])
        ->assertSee('Old title')
        ->assertDontSee('Save task');

    Livewire::test('pages::issues.edit', ['issue' => $task])
        ->set('title', 'New title')
        ->set('description', 'New description')
        ->set('projectId', $otherProject->id)
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($task->fresh())
        ->title->toBe('New title')
        ->body->toBe('New description')
        ->body_html->toBeNull()
        ->project_id->toBe($otherProject->id);
});

test('a member cannot edit a task they did not create and are not assigned to', function () {
    ['owner' => $owner, 'team' => $team] = githubConnectionForOwner();

    $member = attachTeamMember($team, User::factory()->create(['email' => 'member@example.com']));

    $task = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'created_by' => $owner->id,
        'title' => 'Owner task',
    ]);

    $this->actingAs($member);

    $this->get(route('issues.show', $task))->assertForbidden();
    $this->get(route('issues.edit', $task))->assertForbidden();
});

test('syncing a repo does not absorb local tasks or file remote issues into the linked project', function () {
    Http::preventStrayRequests();

    ['source' => $source, 'team' => $team] = githubConnectionForOwner();

    $project = Project::factory()->create([
        'team_id' => $team->id,
        'connected_source_id' => $source->id,
        'name' => 'Client site',
    ]);

    $local = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'title' => 'Local only',
    ]);

    fakeGithubSyncRequests();

    (new SyncConnectedSourceJob($source->id))->handle(
        app(IssueProviderFactory::class),
        app(RefreshIssueFromRemote::class),
    );

    expect(Issue::query()->count())->toBe(2);
    expect($local->fresh()->title)->toBe('Local only');
    expect(Issue::query()->where('external_id', '1001')->first())
        ->project_id->toBeNull()
        ->title->toBe('Fix client login');
});
