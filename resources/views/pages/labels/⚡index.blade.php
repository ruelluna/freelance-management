<?php

use App\Models\Label;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Labels')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|size:6|regex:/^[0-9a-fA-F]{6}$/')]
    public string $color = 'ededed';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Label::class, $this->team()]);
    }

    public function create(): void
    {
        Gate::authorize('create', [Label::class, $this->team()]);

        $validated = $this->validate();

        $this->team()->labels()->firstOrCreate(
            ['name' => $validated['name']],
            ['color' => strtolower($validated['color'])],
        );

        $this->reset('name');
        $this->color = 'ededed';

        Flux::toast(variant: 'success', text: __('Label saved.'));
    }

    public function delete(string $labelId): void
    {
        $label = $this->team()->labels()->findOrFail($labelId);

        Gate::authorize('delete', $label);

        $label->delete();

        Flux::toast(variant: 'success', text: __('Label deleted.'));
    }

    /**
     * @return Collection<int, Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return $this->team()->labels()->withCount('issues')->orderBy('name')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [Label::class, $this->team()]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl">{{ __('Labels') }}</flux:heading>
            <flux:subheading>{{ __('Tags you can attach to tasks. Use a project when the work belongs together.') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <form wire:submit="create" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 md:flex-row md:items-end dark:border-zinc-700">
                <flux:input wire:model="name" :label="__('Name')" class="md:flex-1" data-test="label-name" />
                <flux:input wire:model="color" :label="__('Color')" class="md:w-32" data-test="label-color" />
                <flux:button variant="primary" type="submit" data-test="create-label">{{ __('Add label') }}</flux:button>
            </form>
        @endif

        <div class="space-y-2">
            @forelse ($this->labels as $label)
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="label-{{ $label->id }}" data-test="label-row">
                    <div class="flex items-center gap-3">
                        <span class="size-4 rounded-full" style="background-color: #{{ $label->color }}"></span>
                        <div>
                            <a href="{{ route('issues.index', ['labelId' => $label->id]) }}" class="font-medium hover:underline" wire:navigate>
                                {{ $label->name }}
                            </a>
                            <flux:text class="text-sm text-zinc-500">{{ trans_choice(':count issue|:count issues', $label->issues_count) }}</flux:text>
                        </div>
                    </div>

                    @if ($this->canManage)
                        <flux:button variant="ghost" size="sm" wire:click="delete('{{ $label->id }}')" data-test="delete-label">
                            {{ __('Delete') }}
                        </flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No labels yet. They appear when issues sync, or you can add one here.') }}</flux:text>
            @endforelse
        </div>
    </div>
