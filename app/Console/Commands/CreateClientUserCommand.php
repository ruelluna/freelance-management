<?php

namespace App\Console\Commands;

use App\Actions\Clients\CreateClient;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('client:create')]
#[Description('Create a client user for an existing team')]
class CreateClientUserCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    public function handle(CreateClient $createClient): int
    {
        $team = Team::query()
            ->whereHas('members', fn ($query) => $query->where('team_members.role', TeamRole::Owner->value))
            ->orderBy('id')
            ->first();

        if ($team === null) {
            $this->error('Create an admin first with php artisan admin:create.');

            return self::FAILURE;
        }

        $clientName = trim((string) $this->ask('Client name'));
        $name = trim((string) $this->ask('Name'));
        $email = trim((string) $this->ask('Email'));
        $passwords = $this->passwords();

        if ($passwords === null) {
            return self::FAILURE;
        }

        [$password, $confirmation] = $passwords;

        try {
            Validator::make([
                'client_name' => $clientName,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], [
                'client_name' => ['required', 'string', 'max:255'],
                ...$this->profileRules(),
                'password' => $this->passwordRules(),
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $client = null;

        $user = DB::transaction(function () use ($createClient, $team, $clientName, $name, $email, $password, &$client) {
            $client = $createClient->handle($team, $clientName, $email, null);

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();

            $team->members()->attach($user, ['role' => TeamRole::Client->value]);
            $client->users()->attach($user);
            $user->switchTeam($team);

            return $user;
        });

        $this->info("Client user {$user->email} created.");
        $this->line('Sign in at /'.$client->slug.'/dashboard');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function passwords(): ?array
    {
        $attempts = 3;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Confirm password');

            if ($password === $confirmation) {
                return [$password, $confirmation];
            }

            $this->error('The password confirmation does not match.');
        }

        return null;
    }
}
