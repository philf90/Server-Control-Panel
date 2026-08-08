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

        /*
         * **Dieselben drei Zahlen wie unten, und aus demselben Anlass.** Der
         * Befund kam bei den Datenbanken (siehe {@see measureDatabases()}), die
         * Lücke stand aber in beiden Messungen: `measured` sind die
         * geschriebenen Zeilen, und ein Abonnement ohne Eintrag in der
         * Quota-Datei bekommt eine gemessene Null. „N Abonnement(s) gemessen"
         * hätte also auch eine Quota-Datei bestätigt, aus der nichts zu lesen
         * war.
         */
        $this->info(sprintf(
            '%d Abonnement(s) geschrieben; die Quota-Datei nannte %d Systembenutzer, %d davon zugeordnet.',
            $result['measured'],
            $result['reported'],
            $result['matched'],
        ));

        /*
         * **Ohne Verweis auf ein Werkzeug, weil es keines gibt.** Für ein
         * Schema ohne Zeile ist `srvpanel db --prune` der Weg; für einen
         * Systembenutzer, den kein Abonnement kennt, gibt es kein Gegenstück —
         * sein Heimatverzeichnis liegt dann noch da. Der Verweis auf ein
         * Kommando, das nicht hilft, wäre schlimmer als keiner.
         */
        if ($result['reported'] !== $result['matched']) {
            $this->warn(sprintf(
                '%d Systembenutzer der Quota-Datei waren keinem Abonnement zuzuordnen.',
                $result['reported'] - $result['matched'],
            ));
        }

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

        /*
         * **Drei Zahlen, und die erste allein war kein Beleg.** Der Abnahmelauf
         * vom 8. August 2026 meldete „2 Datenbank(en) gemessen" — und genau das
         * hätte auch dagestanden, wenn die Abfrage gar nichts geliefert hätte:
         * Eine Datenbank ohne Treffer bekommt `size_bytes = 0` als gemessene Null.
         * Nach zwei Läufen war damit unbelegt, ob `db.usage` überhaupt etwas
         * liest.
         *
         * `gemeldet` ist, was der Server genannt hat, `zugeordnet`, wie viel
         * davon zu einer Zeile des Panels passte. Eine Null bei `gemeldet` neben
         * einer Zwei bei `geschrieben` fällt jetzt auf.
         */
        $this->info(sprintf(
            '%d Datenbank(en) geschrieben; der Server meldete %d Schema(ta), %d davon zugeordnet.',
            $result['measured'],
            $result['reported'],
            $result['matched'],
        ));

        /*
         * **Ein Missverhältnis ist eine Warnung und kein Fehlschlag.** Ein
         * Schema, das der Server nennt und das Panel nicht kennt, ist ein
         * Befund für `srvpanel db` — kein Grund, den Zeitgeber rot zu machen.
         * Umgekehrt ist eine Zeile ohne Treffer der Normalfall bei einer
         * frischen, leeren Datenbank.
         */
        if ($result['reported'] !== $result['matched']) {
            $this->warn(sprintf(
                '%d Schema(ta) des Panels waren keiner Zeile zuzuordnen — mit `srvpanel db` nachsehen.',
                $result['reported'] - $result['matched'],
            ));
        }

        return true;
    }
}
