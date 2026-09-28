<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('admin:create')]
#[Description('Create the first admin user')]
class CreateAdminUserCommand extends Command
{
    public function handle(CreateNewUser $createNewUser): int
    {
        if (User::query()->exists()) {
            $this->error('An admin already exists.');

            return self::FAILURE;
        }

        $name = (string) $this->ask('Name');
        $email = (string) $this->ask('Email');
        $passwords = $this->passwords();

        if ($passwords === null) {
            return self::FAILURE;
        }

        [$password, $confirmation] = $passwords;

        $defaultTeamName = $name."'s Team";
        $teamName = trim((string) $this->ask('Team name', $defaultTeamName));

        if ($teamName === '') {
            $teamName = $defaultTeamName;
        }

        try {
            $user = $createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $team = $user->personalTeam();

        if ($team !== null && $teamName !== $defaultTeamName) {
            $team->update([
                'name' => $teamName,
            ]);
        }

        $team?->refresh();

        $this->info("Admin {$user->email} created.");

        if ($team !== null) {
            $this->line('Sign in at /dashboard');
        }

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
