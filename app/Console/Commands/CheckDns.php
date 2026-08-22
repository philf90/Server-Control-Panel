<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Dns\Budget;
use App\Support\Dns\Sweep;
use Illuminate\Console\Command;

/**
 * Den DNS-Abgleich im Hintergrund fahren — ein Durchgang, dann Schluss.
 *
 * **Warum regelmässig und nicht beim Aufruf der Seite.** Der Abgleich fragt
 * fremde Nameserver mit fremden Zeitlimits; eine Seite, die darauf wartet,
 * hängt an einem Dienst, den dieser Server nicht betreibt. Und die Auskunft
 * wird gerade dann gebraucht, wenn niemand hinsieht: Die Domain zeigte gestern
 * hierher und heute nicht mehr, weil beim Anbieter jemand einen Eintrag
 * angefasst hat.
 *
 * **Ein Timer und kein Cronjob** — dieselbe Überlegung wie bei
 * {@see CollectCronRuns}: Das Panel verwaltet cron und hängt seine eigene
 * Verwaltung nicht hinein. Was ihn startet, steht in
 * `packaging/systemd/srvpanel-dns.timer` und ist von aussen sichtbar.
 *
 * **Keine Schalter.** Ein `--force` läge nahe und wäre eine zweite Fassung des
 * Knopfes an der Domain — der misst ohne Rücksicht auf die Frische und ist der
 * Weg für „ich habe gerade etwas umgestellt". Zwei Wege zu derselben Handlung
 * sind einer zu viel.
 */
final class CheckDns extends Command
{
    protected $signature = 'srvpanel:dns-check';

    protected $description = 'Gleicht die Domains mit den autoritativen Nameservern ab — die ältesten zuerst';

    public function handle(Sweep $sweep): int
    {
        $bericht = $sweep->run();

        /*
         * **Was liegen bleibt, wird genannt.** Dieselbe Regel wie bei
         * `remaining` in {@see CollectCronRuns}: Eine Obergrenze, die nichts
         * sagt, wenn sie greift, sieht aus wie „alles gemessen". Hier ist die
         * Zahl sogar die einzige Auskunft darüber, ob der Takt zum Bestand
         * passt — steht sie dauerhaft über null, dauert eine Runde durch alle
         * Domains länger als gedacht.
         */
        $this->info(sprintf(
            '%d Domain/Domains fällig, %d geprüft, %d ohne Antwort, %d wartet/warten noch (%s s).',
            $bericht['due'],
            $bericht['checked'],
            $bericht['silent'],
            $bericht['left'],
            number_format($bericht['seconds'], 1, ',', ''),
        ));

        /*
         * **Ein Fehlschlag beendet den Lauf nicht und wird trotzdem gemeldet.**
         * Er geht an die Standardfehlerausgabe und damit ins Journal der Unit;
         * der Rückgabewert bleibt 0, weil die anderen Domains gemessen worden
         * sind. Ein Timer, der wegen einer kaputten Domain als „failed"
         * dasteht, wird irgendwann abgeschaltet — und dann misst niemand mehr
         * etwas.
         */
        if ($bericht['failed'] > 0) {
            $this->error(sprintf(
                '%d Domain/Domains liessen sich nicht messen — siehe das Protokoll der Anwendung.',
                $bericht['failed'],
            ));
        }

        // Die Grenze steht in der Ausgabe, weil sie die Erklärung für „wartet
        // noch" ist. Ohne sie liest sich die Zahl wie ein Fehler.
        if ($bericht['left'] > 0) {
            $this->line(sprintf(
                '  Die Grenze: %d Domains oder %d Sekunden je Lauf.',
                Budget::DOMAINS,
                Budget::SECONDS,
            ));
        }

        return self::SUCCESS;
    }
}
