<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Web\AccessCounts;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Der Nachtlauf über die Zugriffsprotokolle — B2, zweite Hälfte.
 *
 * **Ein Timer und kein Eintrag im Laravel-Zeitplan**, aus demselben Grund wie
 * bei {@see MeasureUsage}: Der bräuchte `schedule:run` jede Minute, also einen
 * Cron-Eintrag oder einen weiteren Dauerlauf, den es auf diesem Server nicht
 * gibt. Was diesen Lauf startet, steht in
 * `packaging/systemd/srvpanel-traffic.timer` und ist von aussen sichtbar.
 *
 * **Wann er läuft, ist ihm gleichgültig — und das ist gemessen.** `docs/129`
 * verlangte ursprünglich einen Timer „hinter dem von `logrotate` und mit genug
 * Abstand davor". Nachgesehen am 20. September 2026 auf `cloudsrv24`:
 * `logrotate.timer` steht auf `OnCalendar=daily` mit `AccuracySec=1h`. Das ist
 * kein Zeitpunkt, sondern ein Fenster von einer Stunde nach Mitternacht.
 *
 * > **Ein Abstand zu einem Zeitpunkt, den es nicht gibt, lässt sich nicht
 * > einhalten.**
 *
 * `web.access.count` liest deshalb `access.log` **und** `access.log.1`. Vor der
 * Rotation steht der gestrige Tag vollständig in der einen, danach vollständig
 * in der anderen; gruppiert wird nach dem Tag in der Zeile. Der Preis dafür
 * steht hier: Dieser Lauf bekommt regelmässig auch Tage, die er schon hat.
 *
 * **Er legt noch nichts ab.** Die verdichtete Tabelle ist B3 (`docs/129 §6`);
 * bis dahin meldet dieser Lauf, was er gezählt hat, und sonst nichts. Das ist
 * der Zuschnitt aus `docs/129 §3` und keine Auslassung — wenn B3 kommt,
 * schreibt er je Tag **überschreibend** und nicht addierend, denn er sieht
 * denselben Tag mehrfach.
 */
final class CollectTraffic extends Command
{
    protected $signature = 'srvpanel:traffic';

    protected $description = 'Zählt die Zugriffsprotokolle aller Abonnements und meldet, was zählbar war';

    public function handle(Client $agent): int
    {
        try {
            $result = $agent->call('web.access.count', [], [
                'source' => 'cli',
                'command' => 'srvpanel:traffic',
            ]);
        } catch (AgentException $error) {
            $this->error('Zählung scheiterte: '.$error->getMessage());

            return self::FAILURE;
        }

        $totals = is_array($result['totals'] ?? null) ? $result['totals'] : [];
        $split = AccessCounts::split($result, now()->toDateString());

        $this->line(sprintf(
            '  %d Domains gelesen, %d Zeilen, davon %d gedeutet, %d aus dem alten Zeitalter, %d unlesbar.',
            (int) ($totals['domains'] ?? 0),
            (int) ($totals['lines'] ?? 0),
            (int) ($totals['parsed'] ?? 0),
            (int) ($totals['legacy'] ?? 0),
            (int) ($totals['unreadable'] ?? 0),
        ));

        $this->line(sprintf(
            '  %d Tageswerte zählbar, %d übersprungen (gemischtes Format), %d noch offen (laufender Tag).',
            count($split['countable']),
            count($split['skipped']),
            count($split['open']),
        ));

        /*
         * **Was übersprungen wurde, wird benannt und nicht nur gezählt.** Ein
         * übersprungener Tag heisst: Auf dieser Domain schreibt ein
         * Server-Block noch das alte Format. Die Behebung ist ein Aufruf —
         * `srvpanel:vhost --sites` — und wer die Zahl ohne die Namen liest,
         * weiss nicht, ob es eine Domain ist oder vierzig.
         */
        foreach ($split['skipped'] as $eintrag) {
            $this->warn(sprintf(
                '  übersprungen: %s / %s am %s — %d Zeile(n) im alten Format. Behebung: srvpanel:vhost --sites',
                $eintrag['subscription'],
                $eintrag['domain'],
                $eintrag['day'],
                $eintrag['legacy'],
            ));
        }

        /*
         * **Und was das Budget liegen liess, ist ein Fehlschlag.** Es bedeutet,
         * dass dieser Lauf seinen Tag nicht fertig gezählt hat — und der
         * nächste wird es auch nicht, denn die Protokolle werden nicht kürzer.
         * Ein Timer, der darüber grün bliebe, verstiege die Meldung, für die
         * es ihn gibt.
         */
        if ($split['incomplete'] > 0) {
            $this->error(sprintf(
                '  %d Domain(s) blieben ungezählt — das Budget von %d s war aufgebraucht.',
                $split['incomplete'],
                (int) ($result['budget_seconds'] ?? 0),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('  Fertig in %d ms.', (int) ($result['duration_ms'] ?? 0)));

        return self::SUCCESS;
    }
}
