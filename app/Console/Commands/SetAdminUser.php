<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
class SetAdminUser extends Command
{
    protected $signature = 'betlens:admin-user
        {--username= : Administrator username}
        {--email= : Administrator email address}
        {--password-env= : Environment variable containing the password}';

    protected $description = 'Create or update a BetLens administrator account';

    public function handle(): int
    {
        $username = trim((string) ($this->option('username') ?: $this->ask('Utilizator', 'admin')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Email', 'admin@betlens.local')));
        $passwordEnvironment = trim((string) $this->option('password-env'));
        $password = $passwordEnvironment !== ''
            ? (string) getenv($passwordEnvironment)
            : (string) $this->secret('Parolă');

        if ($username === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
            $this->error('Utilizatorul și emailul sunt obligatorii, iar parola trebuie să aibă minimum 12 caractere.');

            return self::FAILURE;
        }

        if ($passwordEnvironment === '' && $password !== (string) $this->secret('Confirmă parola')) {
            $this->error('Parolele nu coincid.');

            return self::FAILURE;
        }

        $user = User::where('username', $username)->orWhere('email', $email)->first() ?? new User;
        $user->fill([
            'name' => 'Administrator BetLens',
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ])->save();

        $this->info('Contul de administrator a fost salvat.');

        return self::SUCCESS;
    }
}
