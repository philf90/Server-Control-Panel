<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Databases\Usage as DatabaseUsage;
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
 *
 * **Seit P5 misst es zweierlei, und trotzdem gibt es einen Timer.** Der belegte
 * Platz der Datenbanken kommt aus `information_schema` und nicht aus der
 * Quota-Datei; das sind zwei Quellen, aber derselbe Anlass. Zwei Zeitgeber im
 * Viertelstundentakt wären zwei Dinge, die jemand überwachen muss, für eine
 * Messung, die dieselbe Frage beantwortet (`docs/36 §9`).
 */
final class MeasureUsage extends Command
{
    protected $signature = 'srvpanel:usage';

    protected $description = 'Misst den belegten Speicher aller Abonnements und den Platz ihrer Datenbanken';

    public function handle(Usage $usage, DatabaseUsage $databases): int
    {
        /*
         * **Die zweite Messung läuft auch dann, wenn die erste nichts liefert.**
         * Sie hängen an verschiedenen Voraussetzungen — `usrquota` am Mount
         * hier, ein laufender MariaDB dort. Ein Server ohne Quota hätte sonst
         * auch keine Datenbankgrössen, und der Grund dafür stünde nirgends.
         */
        $quota = $this->measureDisk($usage);
        $schemas = $this->measureDatabases($databases);

        return $quota && $schemas ? self::SUCCESS : self::FAILURE;
    }

    /** Der belegte Speicher über die Dateisystem-Quota. */
    private function measureDisk(Usage $usage): bool
    {
        try {
            $result = $usage->measure();
        } catch (AgentException $error) {
            $this->error('Messung scheiterte: '.$error->getMessage());

            return false;
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

            return true;
        }

        $this->info(sprintf('%d Abonnement(s) gemessen.', $result['measured']));

        return true;
    }

    /** Der belegte Platz der Datenbanken — dieselbe Nachsicht, anderer Grund. */
    private function measureDatabases(DatabaseUsage $databases): bool
    {
        try {
            $result = $databases->measure();
        } catch (AgentException $error) {
            $this->error('Messung der Datenbanken scheiterte: '.$error->getMessage());

            return false;
        }

        if ($result['available'] === false) {
            $this->warn('Kein Datenbankserver: '.($result['reason'] ?? ''));

            return true;
        }

        $this->info(sprintf('%d Datenbank(en) gemessen.', $result['measured']));

        return true;
    }
}
