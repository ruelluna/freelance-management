<?php

use App\Support\Markdown;

test('markdown renders github style content with images', function () {
    $body = <<<'MD'
Hello team

<img width="1667" height="470" alt="Image" src="https://github.com/user-attachments/assets/example" />

**Bold text**
MD;

    $html = Markdown::toHtml($body);

    expect($html)
        ->toContain('<img')
        ->toContain('src="https://github.com/user-attachments/assets/example"')
        ->toContain('<strong>Bold text</strong>');
});

test('markdown strips html when html is not allowed', function () {
    $html = Markdown::toHtml('<img src="https://example.com/image.png" />', allowHtml: false);

    expect($html)->not->toContain('<img');
});

test('markdown returns an empty string for blank content', function () {
    expect(Markdown::toHtml(null))->toBe('')
        ->and(Markdown::toHtml('   '))->toBe('');
});
