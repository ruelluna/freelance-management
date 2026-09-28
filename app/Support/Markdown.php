<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class Markdown
{
    public static function toHtml(?string $markdown, bool $allowHtml = true): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $environment = new Environment([
            'html_input' => $allowHtml ? 'allow' : 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
