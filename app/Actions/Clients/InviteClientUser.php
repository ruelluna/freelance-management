<?php

namespace App\Actions\Clients;

use App\Enums\TeamRole;
use App\Models\Client;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class InviteClientUser
{
    public function handle(Client $client, string $email): TeamInvitation
    {
        if (! $client->isActive()) {
            throw ValidationException::withMessages([
                'email' => [__('Invitations cannot be sent for archived clients.')],
            ]);
        }

        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if ($existingUser !== null) {
            $existingClient = $existingUser->clientForTeam($client->team);

            if ($existingClient !== null && $existingClient->id !== $client->id) {
                throw ValidationException::withMessages([
                    'email' => [__('This user already belongs to another client on this team.')],
                ]);
            }
        }

        validator(['email' => $email], [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($client->team)],
        ])->validate();

        $invitation = $client->team->invitations()->create([
            'email' => $email,
            'role' => TeamRole::Client,
            'client_id' => $client->id,
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Log::info('Client user invited', [
            'client_id' => $client->id,
            'team_id' => $client->team_id,
            'email' => $email,
        ]);

        return $invitation;
    }
}
