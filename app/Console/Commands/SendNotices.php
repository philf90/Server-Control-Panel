<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Notify\Notices;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Verschickt die fälligen Meldungen (B5 und B1, `docs/129 §4`).
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
 *
 * **Gedruckt wird je Kanal und nicht in einer Summe.** Seit B1 gibt es zwei;
 * eine Zeile „2 Nachrichten verschickt" liesse offen, ob das zweimal Mail war
 * und der Webhook geschwiegen hat.
 */
final class SendNotices extends Command
{
    protected $signature = 'srvpanel:notices';

    protected $description = 'Verschickt die fälligen Meldungen über die eingerichteten Kanäle';

    public function handle(Notices $notices): int
    {
        $jetzt = Carbon::now();
        $fehlschlaege = 0;

        foreach ($notices->send($jetzt) as $kanal => $bilanz) {
            $fehlschlaege += $bilanz['failed'];

            if ($bilanz['skipped'] > 0) {
                $this->warn(sprintf(
                    '  %s: nicht eingerichtet — %d Befund(e) bleiben fällig.',
                    $kanal,
                    $bilanz['skipped'],
                ));

                continue;
            }

            $this->info(sprintf(
                '  %s: %d Nachricht(en) über %d Befund(e).',
                $kanal,
                $bilanz['sent'],
                $bilanz['findings'],
            ));

            if ($bilanz['without_recipient'] > 0) {
                $this->warn(sprintf(
                    '  %s: %d ohne Empfänger — dem Kunden fehlt ein Konto mit Adresse.',
                    $kanal,
                    $bilanz['without_recipient'],
                ));
            }

            if ($bilanz['failed'] > 0) {
                $this->error(sprintf(
                    '  %s: %d Nachricht(en) sind nicht angekommen. Sie bleiben fällig.',
                    $kanal,
                    $bilanz['failed'],
                ));
            }
        }

        /*
         * **Ein nicht eingerichteter Kanal ist kein Fehlschlag.** Ein Server
         * ohne Relay und ohne Meldeziel ist nicht kaputt, er ist unvollständig
         * eingerichtet. Ein Rückgabewert ungleich null hielte `Type=oneshot`
         * für einen Ausfall und färbte die Unit rot — und zwar jede Nacht.
         *
         * Ein **Fehlschlag** ist etwas anderes: Dort war ein Weg eingerichtet
         * und hat nicht getragen, und genau das soll auffallen.
         */
        return $fehlschlaege > 0 ? self::FAILURE : self::SUCCESS;
    }
}
