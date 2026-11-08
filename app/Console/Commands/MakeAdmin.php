<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'love:admin {email} {--name=Administrador} {--password= : senha (se vazio, só promove conta existente)} {--revoke}';

    protected $description = 'Cria ou promove a conta administrativa (ou remove o acesso com --revoke).';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($this->option('revoke')) {
            if (! $user) {
                $this->error('Conta não encontrada.');

                return 1;
            }
            $user->forceFill(['is_admin' => false])->save();
            $this->info("{$email} não é mais admin.");

            return 0;
        }

        if (! $user) {
            if (! $this->option('password') || strlen($this->option('password')) < 12) {
                $this->error('Conta nova: informe --password com 12+ caracteres.');

                return 1;
            }
            $user = User::create([
                'name' => $this->option('name'),
                'email' => $email,
                'password' => $this->option('password'),
                'birth_date' => '1980-01-01',
                'terms_accepted_at' => now(),
            ]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$email} é admin.");

        return 0;
    }
}
