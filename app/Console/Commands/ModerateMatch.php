<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ModerateMatch extends Command
{
    protected $signature = 'love:moderate
        {--approve-photo=* : ids de fotos para aprovar}
        {--reject-photo=* : ids de fotos para apagar}
        {--suspend=* : ids de usuários para suspender}
        {--unsuspend=* : ids de usuários para reativar}
        {--close-report=* : ids de denúncias para fechar}';

    protected $description = 'Moderação do Match público: fotos pendentes, denúncias abertas e suspensões.';

    public function handle(): int
    {
        foreach ($this->option('approve-photo') as $id) {
            DB::table('profile_photos')->where('id', $id)->update(['status' => 'approved']);
        }
        foreach ($this->option('reject-photo') as $id) {
            if ($p = DB::table('profile_photos')->where('id', $id)->first()) {
                Storage::disk('local')->delete($p->path);
                DB::table('profile_photos')->where('id', $id)->delete();
            }
        }
        foreach ($this->option('suspend') as $id) {
            DB::table('users')->where('id', $id)->update(['suspended_at' => now()]);
            DB::table('public_profiles')->where('user_id', $id)->update(['enabled' => false]);
        }
        foreach ($this->option('unsuspend') as $id) {
            DB::table('users')->where('id', $id)->update(['suspended_at' => null]);
        }
        foreach ($this->option('close-report') as $id) {
            DB::table('reports')->where('id', $id)->update(['status' => 'closed']);
        }

        $this->info('Fotos pendentes');
        $this->table(['id', 'usuário'], DB::table('profile_photos')->where('status', 'pending')->orderBy('id')->get(['id', 'user_id'])->map(fn ($r) => (array) $r)->all());
        $this->info('Denúncias abertas');
        $this->table(['id', 'denunciado', 'motivo', 'detalhes'], DB::table('reports')->where('status', 'open')->orderBy('id')->get(['id', 'reported_id', 'reason', 'details'])->map(fn ($r) => (array) $r)->all());

        return self::SUCCESS;
    }
}
