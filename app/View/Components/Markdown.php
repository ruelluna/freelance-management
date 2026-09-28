<?php

namespace App\View\Components;

use App\Support\Markdown as MarkdownRenderer;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Markdown extends Component
{
    public function __construct(
        public ?string $content = null,
        public bool $allowHtml = true,
    ) {}

    public function html(): string
    {
        return MarkdownRenderer::toHtml($this->content, $this->allowHtml);
    }

    public function render(): View|Closure|string
    {
        return view('components.markdown');
    }
}
