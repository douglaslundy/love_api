<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifier;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'love:reminders';

    protected $description = 'Envia o lembrete neutro de quem escolheu um horário (roda a cada minuto pelo cron).';

    public function handle(): int
    {
        $sent = 0;

        User::whereNotNull('reminder_time')->each(function (User $u) use (&$sent) {
            $now = now($u->timezone ?: 'America/Sao_Paulo');

            if ($u->reminder_time === $now->format('H:i') && $u->last_reminder_on?->toDateString() !== $now->toDateString()) {
                $u->forceFill(['last_reminder_on' => $now->toDateString()])->save();
                Notifier::reminder($u);
                $sent++;
            }
        });

        $this->info("Lembretes enviados: {$sent}");

        return self::SUCCESS;
    }
}
