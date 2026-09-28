<?php

use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('team members can view cached issue media', function () {
    ['owner' => $owner, 'team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'connected_source_id' => $source->id,
    ]);

    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';
    Storage::disk('local')->put("issue-media/{$issue->id}/{$assetUuid}.png", 'png-bytes');

    $this->actingAs($owner)
        ->get(route('issues.media', [
            'current_team' => $team->slug,
            'issue' => $issue,
            'asset' => $assetUuid,
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

test('issue media cannot be accessed across teams', function () {
    ['connection' => $connection, 'source' => $source] = githubConnectionForOwner();
    $otherOwner = User::factory()->create(['email' => 'other@example.com']);
    $otherTeam = $otherOwner->currentTeam;

    $issue = Issue::factory()->create([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
        'connected_source_id' => $source->id,
    ]);

    Storage::disk('local')->put("issue-media/{$issue->id}/9498dba1-d084-4319-aafc-d35c0de16cb7.png", 'png-bytes');

    $this->actingAs($otherOwner)
        ->get(route('issues.media', [
            'current_team' => $otherTeam->slug,
            'issue' => $issue,
            'asset' => '9498dba1-d084-4319-aafc-d35c0de16cb7',
        ]))
        ->assertNotFound();
});

test('invalid issue media asset ids are rejected', function () {
    ['owner' => $owner, 'team' => $team, 'source' => $source, 'connection' => $connection] = githubConnectionForOwner();

    $issue = Issue::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'connected_source_id' => $source->id,
    ]);

    $this->actingAs($owner)
        ->get(route('issues.media', [
            'current_team' => $team->slug,
            'issue' => $issue,
            'asset' => 'not-a-valid-uuid',
        ]))
        ->assertNotFound();
});

test('the task page replaces github user attachments with cached images', function () {
    Http::preventStrayRequests();

    ['owner' => $owner, 'team' => $team, 'connection' => $connection, 'source' => $source] = githubConnectionForOwner();

    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';
    $src = 'https://github.com/user-attachments/assets/'.$assetUuid;

    Http::fake([
        'https://github.com/user-attachments/assets/*' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $issue = Issue::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'connected_source_id' => $source->id,
        'last_synced_at' => now(),
        'title' => 'Screenshot task',
        'body' => '<img src="'.$src.'" alt="Image" />',
        'body_html' => '<p><img src="'.$src.'" alt="Image"></p>',
    ]);

    $this->actingAs($owner);

    $html = Livewire::test('pages::issues.show', ['issue' => $issue])->html();

    expect($html)->toContain('/issues/'.$issue->id.'/media/'.$assetUuid)
        ->not->toContain('src="'.$src.'"');

    Storage::disk('local')->assertExists("issue-media/{$issue->id}/{$assetUuid}.png");
});
