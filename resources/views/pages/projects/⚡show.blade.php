<?php

use App\Actions\Issues\AssignIssue;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Services\TeamResourceAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Project')] class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        abort_unless($project->team_id === $this->team()->id, 404);

        Gate::authorize('view', $project);

        $this->project = $project->load($this->projectRelations());
    }

    #[On('task-created')]
    public function refreshTasks(): void
    {
        unset($this->tasks);
    }

    public function assignTask(string $issueId, string $userId, AssignIssue $action): void
    {
        $issue = $this->project->issues()->findOrFail($issueId);

        Gate::authorize('assign', $issue);

        $action->handle($issue, $userId === '' ? [] : [$userId]);

        unset($this->tasks);
    }

    /**
     * @return Collection<int, Issue>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())
            ->scopeIssues($this->project->issues())
            ->with(['assignees', 'labels'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('update', $this->project);
    }

    #[Computed]
    public function canManageConnections(): bool
    {
        return Auth::user()->can('create', [Connection::class, $this->team()]);
    }

    #[Computed]
    public function canCreateTask(): bool
    {
        return Auth::user()->can('createOnProject', $this->project);
    }

    /**
     * @return Collection<int, \App\Models\User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return TeamResourceAccess::for(Auth::user(), $this->team())->assignableUsers();
    }

    /**
     * @return array<int, string>
     */
    protected function projectRelations(): array
    {
        $relations = ['client'];

        if (! Auth::user()->isTeamClient($this->team())) {
            $relations[] = 'connectedSource';
        }

        return $relations;
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <a href="{{ route(auth()->user()->sectionRoute('projects.index')) }}" class="text-sm text-zinc-500 hover:underline" wire:navigate>{{ __('Back to projects') }}</a>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $project->name }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-dark-300">
                {{ $project->client->name ?? __('Internal') }}
                @unless (auth()->user()->isTeamClient(auth()->user()->currentTeam))
                    · {{ $project->connectedSource->name ?? __('No repository') }}
                @endunless
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-badge light data-test="project-status" :color="$project->status === \App\Enums\ProjectStatus::Open ? 'green' : 'gray'" :text="$project->status->label()" />
            @if ($this->canManage)
                <x-button
                    outline
                    icon="pencil-square"
                    :href="route(auth()->user()->sectionRoute('projects.edit'), $project)"
                    wire:navigate
                    data-test="edit-project"
                    :text="__('Edit')"
                />
            @endif
            @if ($this->canManageConnections)
                <x-button
                    outline
                    icon="link"
                    :href="route(auth()->user()->sectionRoute('projects.integrations'), $project)"
                    wire:navigate
                    data-test="project-integrations"
                    :text="__('Integrations')"
                />
            @endif
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Tasks') }}</h2>
            @if ($this->canCreateTask)
                <livewire:issues.task-form :project-id="$project->id" :lock-project="true" :key="'task-form-'.$project->id" />
            @endif
        </div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-dark-700">
            <table class="min-w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-medium tracking-wide text-gray-500 uppercase dark:border-dark-700 dark:bg-dark-800 dark:text-dark-300">
                    <tr>
                        <th class="px-3 py-2">{{ __('Task') }}</th>
                        <th class="px-3 py-2">{{ __('Status') }}</th>
                        <th class="px-3 py-2">{{ __('Assignee') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-dark-700">
                    @forelse ($this->tasks as $task)
                        <tr wire:key="project-task-{{ $task->id }}" data-test="project-task" class="hover:bg-zinc-50 dark:hover:bg-dark-800">
                            <td class="px-3 py-2">
                                <a href="{{ route(auth()->user()->sectionRoute('issues.show'), $task) }}" class="font-medium hover:underline" wire:navigate>{{ $task->title }}</a>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <x-badge sm light :color="$task->status === \App\Enums\IssueStatus::Open ? 'green' : 'gray'" :text="$task->status->label()" />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @can('assign', $task)
                                    <select
                                        wire:change="assignTask('{{ $task->id }}', $event.target.value)"
                                        data-test="project-table-assignee"
                                        class="w-full max-w-48 rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm text-gray-700 dark:border-dark-600 dark:bg-dark-900 dark:text-white"
                                    >
                                        <option value="">{{ __('Unassigned') }}</option>
                                        @if ($task->assignees->count() > 1)
                                            <option value="multiple" selected disabled>{{ $task->assignees->pluck('name')->join(', ') }}</option>
                                        @endif
                                        @foreach ($this->staff as $member)
                                            <option
                                                value="{{ $member->id }}"
                                                @selected($task->assignees->count() === 1 && $task->assignees->first()->id === $member->id)
                                            >{{ $member->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-gray-500 dark:text-dark-300">{{ $task->assignees->pluck('name')->join(', ') ?: __('Unassigned') }}</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-6 text-center text-gray-500 dark:text-dark-300">{{ __('No tasks in this project yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($project->description)
        <x-card>
            <x-markdown :content="$project->description" :allow-html="false" />
        </x-card>
    @endif
</div>
