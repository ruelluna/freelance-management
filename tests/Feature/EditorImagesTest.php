<?php

use App\Actions\Issues\AddIssueComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\UpdateIssue;
use App\Data\Integrations\IssueUpdate;
use App\Enums\CommentAudience;
use App\Models\EditorImage;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

test('an uploaded image is attached when an issue is saved', function () {
    ['owner' => $owner, 'team' => $team, 'assignedProject' => $project] = clientPortalFixtures();

    $this->actingAs($owner);

    $editorImage = uploadEditorImage($owner);

    $created = app(CreateIssue::class)->handle(
        $team,
        $owner,
        $project,
        'Screenshot task',
        editorImageMarkdown($editorImage),
        [],
        [],
        false,
    );

    $editorImage->refresh();

    expect($created->issue->body)->toContain($editorImage->id)
        ->and($editorImage->imageable_id)->toBe($created->issue->id)
        ->and($editorImage->imageable_type)->toBe($created->issue->getMorphClass());

    Storage::disk('local')->assertExists($editorImage->path);

    $this->get(route('editor-images.show', [
        'current_team' => $team->slug,
        'editorImage' => $editorImage,
    ]))->assertOk()
        ->assertHeader('Content-Type', $editorImage->mime_type);
});

test('editor images cannot be accessed across teams', function () {
    ['owner' => $owner, 'team' => $team, 'assignedProject' => $project] = clientPortalFixtures();
    $otherOwner = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($owner);

    $editorImage = uploadEditorImage($owner);

    app(CreateIssue::class)->handle(
        $team,
        $owner,
        $project,
        'Screenshot task',
        editorImageMarkdown($editorImage),
        [],
        [],
        false,
    );

    $this->actingAs($otherOwner)
        ->get(route('editor-images.show', [
            'current_team' => $otherOwner->currentTeam->slug,
            'editorImage' => $editorImage,
        ]))
        ->assertNotFound();
});

test('a client can view an image on an issue they can read', function () {
    ['owner' => $owner, 'team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $this->actingAs($owner);

    $editorImage = uploadEditorImage($owner);

    app(CreateIssue::class)->handle(
        $team,
        $owner,
        $project,
        'Screenshot task',
        editorImageMarkdown($editorImage),
        [],
        [],
        false,
    );

    $this->actingAs($clientUser)
        ->followingRedirects()
        ->get(route('editor-images.show', [
            'current_team' => $team->slug,
            'editorImage' => $editorImage,
        ]))
        ->assertOk();
});

test('a client cannot view an image on a team note', function () {
    ['owner' => $owner, 'team' => $team, 'client' => $client, 'assignedProject' => $project] = clientPortalFixtures();

    $clientUser = User::factory()->create(['email' => 'client@acme.test']);
    attachClientUser($team, $client, $clientUser);

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'title' => 'Update the homepage logo',
    ]);

    $this->actingAs($owner);

    $editorImage = uploadEditorImage($owner);

    app(AddIssueComment::class)->handle(
        $issue,
        $owner,
        editorImageMarkdown($editorImage),
        CommentAudience::Internal,
    );

    $this->actingAs($clientUser)
        ->followingRedirects()
        ->get(route('editor-images.show', [
            'current_team' => $team->slug,
            'editorImage' => $editorImage,
        ]))
        ->assertNotFound();
});

test('removing an image from a description deletes the file', function () {
    ['owner' => $owner, 'team' => $team, 'assignedProject' => $project] = clientPortalFixtures();

    $this->actingAs($owner);

    $editorImage = uploadEditorImage($owner);
    $path = $editorImage->path;

    $created = app(CreateIssue::class)->handle(
        $team,
        $owner,
        $project,
        'Screenshot task',
        editorImageMarkdown($editorImage),
        [],
        [],
        false,
    );

    app(UpdateIssue::class)->handle(
        $created->issue,
        new IssueUpdate(body: 'No image'),
        $owner,
    );

    Storage::disk('local')->assertMissing($path);
    expect(EditorImage::query()->whereKey($editorImage->id)->exists())->toBeFalse()
        ->and($created->issue->fresh()->body)->toBe('No image');
});

test('svg uploads are rejected', function () {
    ['owner' => $owner] = clientPortalFixtures();

    $this->actingAs($owner);

    Livewire::test('markdown-editor')
        ->set('image', UploadedFile::fake()->create('icon.svg', 20, 'image/svg+xml'))
        ->call('storeImage')
        ->assertHasErrors('image');

    expect(EditorImage::query()->count())->toBe(0);
});

function uploadEditorImage(User $user): EditorImage
{
    $existingIds = EditorImage::query()->pluck('id');

    Livewire::actingAs($user)
        ->test('markdown-editor')
        ->set('image', UploadedFile::fake()->image('diagram.png'))
        ->call('storeImage')
        ->assertHasNoErrors();

    return EditorImage::query()->whereNotIn('id', $existingIds)->sole();
}

function editorImageMarkdown(EditorImage $image): string
{
    return '![diagram]('.$image->url().')';
}
