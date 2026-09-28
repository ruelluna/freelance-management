<?php

namespace App\View\Components;

use App\Support\HtmlLinks;
use App\Support\Markdown as MarkdownRenderer;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Markdown extends Component
{
    public function __construct(
        public ?string $content = null,
        public bool $allowHtml = true,
        public bool $newTab = false,
    ) {}

    public function html(): string
    {
        $html = MarkdownRenderer::toHtml($this->content, $this->allowHtml);

        if (! $this->newTab) {
            return $html;
        }

        return HtmlLinks::openInNewTab($html);
    }

    public function render(): View|Closure|string
    {
        return view('components.markdown');
    }
}
