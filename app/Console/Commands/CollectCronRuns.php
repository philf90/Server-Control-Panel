<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Cron\Cron;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;

/**
 * Die aufgezeichneten Cron-Läufe einsammeln — ein Lauf, dann Schluss.
 *
 * **Ein Timer und kein Cronjob**, und das ist an dieser Stelle keine
 * Kleinigkeit: Das Panel verwaltet cron, also darf es nicht selbst darin
 * stehen. Ein Eintrag unter `/etc/cron.d`, der das Einsammeln startet, läge in
 * demselben Verzeichnis, das `cron.apply` verwaltet — und der erste Fehler, der
 * dort eine Datei verwirft, nähme das Einsammeln mit.
 *
 * > **Wer ein System verwaltet, hängt seine eigene Verwaltung nicht hinein.**
 *
 * Dieselbe Bauart wie {@see MeasureUsage}: `Type=oneshot` mit einem Timer
 * daneben, nicht `schedule:run` und kein Dauerlauf. Was ihn startet, steht in
 * `packaging/systemd/srvpanel-cron.timer` und ist von aussen sichtbar.
 *
 * **Warum überhaupt regelmässig.** Die Läufe könnten auch beim Aufruf der Seite
 * eingesammelt werden. Dann stünde die Liste aber nur da, wenn jemand hinsieht —
 * und die Ablage liefe voll, solange niemand hinsieht. Ein Kunde mit einem
 * Minutenjob und drei Wochen Urlaub hinterliesse 30240 Dateien.
 */
final class CollectCronRuns extends Command
{
    protected $signature = 'srvpanel:cron-runs';

    protected $description = 'Sammelt die aufgezeichneten Läufe der Cronjobs ein und pflegt sie ein';

    public function handle(Cron $cron): int
    {
        try {
            $ergebnis = $cron->ingest();
        } catch (AgentException $error) {
            /*
             * **Ein Fehler hier ist kein Grund, den Timer scheitern zu lassen —
             * aber einer, ihn zu nennen.** `docs/44` hat vorgeführt, was ein
             * stilles `catch` anrichtet: Aus „nicht erreichbar" wurde „der
             * Betreiber bietet es nicht an". Die Meldung geht an die
             * Standardfehlerausgabe und damit ins Journal der Unit.
             */
            $this->error('Die Läufe liessen sich nicht einsammeln: '.$error->getMessage());

            return self::FAILURE;
        }

        /*
         * **`remaining` wird genannt und nicht verschwiegen.** Der Agent
         * deckelt bei 500 Läufen je Aufruf; bleibt etwas liegen, ist das kein
         * Fehler, aber es ist eine Auskunft — und zwar die, an der man merkt,
         * dass der Timer zu selten läuft.
         *
         * > **Eine Obergrenze, die nichts sagt, wenn sie greift, sieht aus wie
         * > „alles eingesammelt".**
         */
        $this->info(sprintf(
            '%d Lauf/Läufe eingesammelt, %d eingepflegt, %d wartet/warten noch.',
            $ergebnis['taken'],
            $ergebnis['stored'],
            $ergebnis['remaining'],
        ));

        return self::SUCCESS;
    }
}
