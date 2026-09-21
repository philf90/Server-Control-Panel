<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Notify\Notices;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Verschickt die fälligen Meldungen an die Kunden (B5, `docs/129 §9`).
 *
 * **Ein eigenes Kommando und kein Teil von `srvpanel:diagnose`.** Die Diagnose
 * misst und schreibt Befunde; dieses hier schickt etwas nach draussen. Zwei
 * Dinge, zwei Kommandos — und wer den Versand von Hand nachholen will, muss
 * dafür nicht den ganzen Nachtlauf noch einmal fahren.
 *
 * **Gefahren wird es trotzdem aus derselben Unit**, als zweite `ExecStart`-Zeile
 * von `srvpanel-diagnose.service`. Ein eigener Zeitgeber könnte vor der
 * Messung feuern und meldete dann den Stand von gestern; `Type=oneshot` führt
 * seine Zeilen der Reihe nach aus und bricht ab, wenn die erste scheitert.
 *
 * > **Eine Reihenfolge, die ein Zeitgeber herstellen soll, ist keine.**
 */
final class SendNotices extends Command
{
    protected $signature = 'srvpanel:notices';

    protected $description = 'Verschickt die fälligen Meldungen über überschrittene Kontingente an die Kunden';

    public function handle(Notices $notices): int
    {
        $jetzt = Carbon::now();
        $bilanz = $notices->send($jetzt);

        if ($bilanz['skipped'] > 0) {
            $this->warn(sprintf(
                '  Kein Mailversand eingerichtet — %d Befund(e) bleiben fällig.',
                $bilanz['skipped'],
            ));

            // Kein Fehlschlag: Ein Server ohne eingetragenes Relay ist nicht
            // kaputt, er ist unvollständig eingerichtet. Ein Rückgabewert
            // ungleich null hielte `Type=oneshot` für einen Ausfall und
            // färbte die Unit rot.
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '  %d Nachricht(en) verschickt über %d Befund(e).',
            $bilanz['sent'],
            $bilanz['findings'],
        ));

        if ($bilanz['without_recipient'] > 0) {
            $this->warn(sprintf(
                '  %d Abonnement(s) ohne Empfänger — dem Kunden fehlt ein Konto mit Adresse.',
                $bilanz['without_recipient'],
            ));
        }

        if ($bilanz['failed'] > 0) {
            $this->error(sprintf(
                '  %d Nachricht(en) sind nicht angekommen. Sie bleiben fällig und werden erneut versucht.',
                $bilanz['failed'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
