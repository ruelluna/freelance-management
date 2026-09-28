<?php

use App\Models\Connection;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Integrations')] class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('view', $project);
        Gate::authorize('viewAny', [Connection::class, $this->team()]);

        $this->project = $project;
    }

    #[On('project-integration-updated')]
    public function refreshProject(): void
    {
        $this->project = $this->project->fresh() ?? $this->project;
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route(auth()->user()->sectionRoute('projects.show'), $project) }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to project') }}</a>
    </div>

    <livewire:projects.integrations :project="$project" :key="'project-integrations-'.$project->id" />
</div>
