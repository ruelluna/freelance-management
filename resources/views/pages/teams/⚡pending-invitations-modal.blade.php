<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new class extends Component {
    use Interactions;

    public bool $showPendingInvitationsModal = true;

    public function mount(): void
    {
        if (session()->pull('team-invitation-accepted')) {
            $this->toast()->success(__('Invitation accepted.'))->send();
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{code: string, inviter_name: string, team_name: string}>
     */
    #[Computed]
    public function pendingInvitations(): \Illuminate\Support\Collection
    {
        $email = Str::lower(Auth::user()->email);

        return TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviter_name' => $invitation->inviter->name,
                'team_name' => $invitation->team->name,
            ]);
    }

    public function acceptInvitation(string $code): void
    {
        $invitation = $this->findPendingInvitation($code);

        $user = Auth::user();

        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role]
            );

            if ($invitation->client_id !== null) {
                $invitation->loadMissing('client');

                if ($invitation->client !== null) {
                    $invitation->client->users()->syncWithoutDetaching([$user->id]);
                }
            }

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        session()->flash('team-invitation-accepted', true);

        $this->redirectRoute($user->homeRoute(), navigate: true);
    }

    public function declineInvitation(string $code): void
    {
        $invitation = $this->findPendingInvitation($code);

        $invitation->delete();

        $this->toast()->success(__('Invitation declined.'))->send();
    }

    private function findPendingInvitation(string $code): TeamInvitation
    {
        $invitation = TeamInvitation::query()
            ->where('code', $code)
            ->whereNull('accepted_at')
            ->firstOrFail();

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if (Str::lower($invitation->email) !== Str::lower(Auth::user()->email)) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation was sent to a different email address.')],
            ]);
        }

        return $invitation;
    }
}; ?>

<div>
    @if ($this->pendingInvitations->isNotEmpty())
        <x-modal id="pending-invitations" wire="showPendingInvitationsModal" :title="__('Pending team invitations')" center size="lg">
            <div data-test="pending-invitations-modal" class="space-y-6">
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Accept or decline the teams you have been invited to join.') }}</p>

                <div class="grid gap-4">
                    @foreach ($this->pendingInvitations as $invitation)
                        <div data-test="pending-invitation-row" class="rounded-lg border border-zinc-200 p-4 dark:border-dark-700">
                            <div class="space-y-1">
                                <p class="font-medium text-gray-900 dark:text-white">{{ $invitation['team_name'] }}</p>
                                <p class="text-sm text-gray-500 dark:text-dark-300">
                                    {{ __(':inviter invited you to join this team.', ['inviter' => $invitation['inviter_name']]) }}
                                </p>
                            </div>

                            <div class="mt-4 flex justify-end gap-2">
                                <x-button
                                    outline
                                    wire:click="declineInvitation('{{ $invitation['code'] }}')"
                                    wire:loading.attr="disabled"
                                    data-test="pending-invitation-decline"
                                    :text="__('Decline')"
                                />

                                <x-button
                                    wire:click="acceptInvitation('{{ $invitation['code'] }}')"
                                    wire:loading.attr="disabled"
                                    data-test="pending-invitation-accept"
                                    :text="__('Accept')"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-modal>
    @endif
</div>
