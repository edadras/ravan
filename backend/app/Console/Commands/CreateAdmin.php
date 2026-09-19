<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or promotes) an administrator. This is the only way to get the first
 * admin account on a fresh install — there is deliberately no public sign-up
 * path to the admin role, and no default administrator is ever seeded.
 */
class CreateAdmin extends Command
{
    protected $signature = 'ravan:create-admin
        {--name= : Display name}
        {--email= : E-mail address (the login)}
        {--password= : Password; a strong one is generated when omitted}
        {--locale=fa : Interface language (fa, en, tr)}';

    protected $description = 'Create an administrator account, or promote an existing user to admin';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('E-mail');
        $generated = null;
        $password = $this->option('password');
        if (! $password && $this->input->isInteractive()) {
            $password = $this->secret('Password (blank to generate one)');
        }
        if (! $password) {
            $password = $generated = Str::password(20);
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'locale' => $this->option('locale')],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
                'locale' => ['required', 'in:fa,en,tr'],
            ]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing) {
            if (! $this->confirmOrForce("{$email} already exists. Promote it to admin and reset its password?")) {
                return self::FAILURE;
            }
            $existing->restore();
            $existing->forceFill([
                'role' => Role::Admin,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();
            $user = $existing;
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => Role::Admin,
                'locale' => $this->option('locale'),
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('ravan.terms_version'),
            ]);
            $user->forceFill(['is_active' => true])->save();
        }

        $this->info("Administrator ready: {$user->email} (id {$user->id})");
        if ($generated) {
            $this->newLine();
            $this->warn("Generated password: {$generated}");
            $this->warn('Store it now — it is not shown again and is not written to any log.');
        }

        return self::SUCCESS;
    }

    protected function confirmOrForce(string $question): bool
    {
        return ! $this->input->isInteractive() || $this->confirm($question, false);
    }
}
