<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class IssueHtml extends Component
{
    public function __construct(
        public ?string $content = null,
    ) {}

    public function render(): View|Closure|string
    {
        return view('components.issue-html');
    }
}
