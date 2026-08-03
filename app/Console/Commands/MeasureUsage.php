<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Subscriptions\Usage;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;

/**
 * Der belegte Speicher aller Abonnements — eine Messung, dann Schluss.
 *
 * **Ein Timer und kein Dauerlauf.** Der Kennzahlensammler läuft als Prozess,
 * weil er im Zehnsekundentakt messen muss und der Zeitplan von Laravel nur
 * Minutenauflösung hat. Hier ist es umgekehrt: Der belegte Speicher ändert
 * sich langsam, eine Viertelstunde ist reichlich genau, und ein Prozess, der
 * 899 von 900 Sekunden schläft, ist ein Prozess, den jemand überwachen muss.
 *
 * **Auch kein Eintrag im Laravel-Zeitplan.** Der bräuchte `schedule:run` jede
 * Minute — also einen Cron-Eintrag oder einen weiteren Dauerlauf, den es auf
 * diesem Server nicht gibt. Der Timer ruft dieses Kommando direkt auf; was
 * ihn startet, steht in `packaging/systemd/srvpanel-usage.timer` und ist von
 * aussen sichtbar.
 */
final class MeasureUsage extends Command
{
    protected $signature = 'srvpanel:usage';

    protected $description = 'Misst den belegten Speicher aller Abonnements über die Dateisystem-Quota';

    public function handle(Usage $usage): int
    {
        try {
            $result = $usage->measure();
        } catch (AgentException $error) {
            $this->error('Messung scheiterte: '.$error->getMessage());

            return self::FAILURE;
        }

        if ($result['available'] === false) {
            /*
             * **Kein Fehlschlag.** Eine Installation ohne `usrquota` auf dem
             * Mount ist eingerichtet, wie sie eingerichtet ist — der Timer
             * würde sonst alle fünfzehn Minuten rot und stünde damit dauerhaft
             * neben allem, was tatsächlich kaputt ist. Der Grund steht im
             * Journal, und die Oberfläche zeigt „nicht gemessen".
             */
            $this->warn('Keine Dateisystem-Quota: '.($result['reason'] ?? ''));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d Abonnement(s) gemessen.', $result['measured']));

        return self::SUCCESS;
    }
}
