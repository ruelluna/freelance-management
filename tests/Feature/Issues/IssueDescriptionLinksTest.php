<?php

use App\Models\Issue;
use App\Models\IssueComment;
use Livewire\Livewire;

test('stored description links open in a new tab and comment links do not', function () {
    ['owner' => $owner, 'team' => $team] = githubConnectionForOwner();

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'title' => 'Linked task',
        'body' => 'See [the docs](https://example.com/docs).',
        'body_html' => '<p>Read <a href="https://example.com/guide" target="_self">the guide</a>.</p>',
    ]);

    IssueComment::factory()->local()->create([
        'issue_id' => $issue->id,
        'body' => 'See [the comment](https://example.com/comment).',
        'body_html' => '<p>See <a href="https://example.com/comment">the comment</a>.</p>',
        'author_name' => 'Avery Owner',
    ]);

    $this->actingAs($owner);

    $html = Livewire::test('pages::issues.show', ['issue' => $issue])->html();

    expect(anchorTag($html, 'https://example.com/guide'))
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->not->toContain('target="_self"');

    expect(anchorTag($html, 'https://example.com/comment'))
        ->not->toContain('target="_blank"');
});

test('markdown description links open in a new tab', function () {
    ['owner' => $owner, 'team' => $team] = githubConnectionForOwner();

    $issue = Issue::factory()->local()->create([
        'team_id' => $team->id,
        'title' => 'Markdown task',
        'body' => 'See [the docs](https://example.com/docs).',
        'body_html' => null,
    ]);

    $this->actingAs($owner);

    $html = Livewire::test('pages::issues.show', ['issue' => $issue])->html();

    expect(anchorTag($html, 'https://example.com/docs'))
        ->toContain('target="_blank"')
        ->toContain('noopener');
});

function anchorTag(string $html, string $href): string
{
    $pattern = '/<a\b[^>]*href="'.preg_quote($href, '/').'"[^>]*>/';

    expect($html)->toMatch($pattern);

    preg_match($pattern, $html, $matches);

    return $matches[0];
}
