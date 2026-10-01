<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetAdminPassword extends Command
{
    protected $signature='betlens:admin-password';
    protected $description='Generate a secure password hash for the BetLens administrator';

    public function handle(): int
    {
        $password=(string)$this->secret('Parola nouă de administrator');
        if(mb_strlen($password)<12){
            $this->error('Parola trebuie să conțină minimum 12 caractere.');
            return self::FAILURE;
        }

        if($password!==(string)$this->secret('Confirmă parola')){
            $this->error('Parolele nu coincid.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Adaugă această valoare în mediul securizat al serverului:');
        $this->line('BETLENS_ADMIN_PASSWORD_HASH='.Hash::make($password));
        return self::SUCCESS;
    }
}
