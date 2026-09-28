<?php

use App\Support\HtmlLinks;

test('description links open in a new tab', function () {
    $html = '<p>Read <a href="https://example.com/guide" target="_self" rel="nofollow">the café guide</a> and <a href="https://example.com/docs">docs</a>.</p>';

    $rendered = HtmlLinks::openInNewTab($html);

    expect($rendered)
        ->toContain('href="https://example.com/guide"')
        ->toContain('target="_blank"')
        ->toContain('rel="nofollow noopener noreferrer"')
        ->toContain('the café guide')
        ->toContain('href="https://example.com/docs" target="_blank" rel="noopener noreferrer"')
        ->not->toContain('target="_self"');
});

test('html without links is left unchanged', function () {
    expect(HtmlLinks::openInNewTab('<p>No links here.</p>'))->toBe('<p>No links here.</p>')
        ->and(HtmlLinks::openInNewTab(''))->toBe('')
        ->and(HtmlLinks::openInNewTab('   '))->toBe('   ');
});
