<?php

namespace App\View\Components;

use App\Support\HtmlLinks;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class IssueHtml extends Component
{
    public function __construct(
        public ?string $content = null,
        public bool $newTab = false,
    ) {}

    public function html(): string
    {
        $content = $this->content ?? '';

        if (! $this->newTab) {
            return $content;
        }

        return HtmlLinks::openInNewTab($content);
    }

    public function render(): View|Closure|string
    {
        return view('components.issue-html');
    }
}
