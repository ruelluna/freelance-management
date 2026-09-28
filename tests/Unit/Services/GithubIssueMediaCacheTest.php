<?php

use App\Models\Issue;
use App\Services\Integrations\GithubIssueMediaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    URL::defaults(['current_team' => 'acme-team']);
});

test('github issue media cache rewrites image sources and stores bytes locally', function () {
    Http::fake([
        'https://private-user-images.githubusercontent.com/*' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $issue = Issue::factory()->create();
    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';
    $html = '<p>Hi</p><img alt="Image" src="https://private-user-images.githubusercontent.com/123/'.$assetUuid.'.png?jwt=abc" />';

    $rewritten = app(GithubIssueMediaCache::class)->cacheAndRewrite($issue, $html);

    expect($rewritten)
        ->toContain('/issues/'.$issue->id.'/media/'.$assetUuid)
        ->not->toContain('private-user-images.githubusercontent.com');

    Storage::disk('local')->assertExists("issue-media/{$issue->id}/{$assetUuid}.png");
});

test('github issue media cache matches markdown attachment uuids by order', function () {
    Http::fake([
        'https://private-user-images.githubusercontent.com/*' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $issue = Issue::factory()->create();
    $assetUuid = '84507090-13c5-45e1-bb1d-0c9bfd62a5cf';
    $markdown = '<img src="https://github.com/user-attachments/assets/'.$assetUuid.'" />';
    $html = '<img alt="Image" src="https://private-user-images.githubusercontent.com/123/photo.png?jwt=abc" />';

    $rewritten = app(GithubIssueMediaCache::class)->cacheAndRewrite($issue, $html, $markdown);

    expect($rewritten)->toContain('/issues/'.$issue->id.'/media/'.$assetUuid);
    Storage::disk('local')->assertExists("issue-media/{$issue->id}/{$assetUuid}.png");
});

test('github issue media cache downloads user attachments with the connection token', function () {
    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';

    Http::fake([
        'https://github.com/user-attachments/assets/*' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $issue = Issue::factory()->create();
    $html = '<img alt="Image" src="https://github.com/user-attachments/assets/'.$assetUuid.'" />';

    $rewritten = app(GithubIssueMediaCache::class)->cacheAndRewrite($issue, $html);

    expect($rewritten)->toContain('/issues/'.$issue->id.'/media/'.$assetUuid)
        ->not->toContain('github.com/user-attachments/assets/'.$assetUuid);

    Storage::disk('local')->assertExists("issue-media/{$issue->id}/{$assetUuid}.png");

    Http::assertSent(function (Request $request) use ($issue, $assetUuid): bool {
        return $request->url() === 'https://github.com/user-attachments/assets/'.$assetUuid
            && $request->hasHeader('Authorization', 'Bearer '.$issue->connection->token);
    });
});

test('github issue media cache ignores attachment responses that are not images', function () {
    $assetUuid = '9498dba1-d084-4319-aafc-d35c0de16cb7';
    $src = 'https://github.com/user-attachments/assets/'.$assetUuid;

    Http::fake([
        'https://github.com/user-attachments/assets/*' => Http::response('<html>sign in</html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $issue = Issue::factory()->create();

    $rewritten = app(GithubIssueMediaCache::class)->cacheAndRewrite($issue, '<img src="'.$src.'" />');

    expect($rewritten)->toContain($src);
    Storage::disk('local')->assertMissing("issue-media/{$issue->id}/{$assetUuid}.png");
});
