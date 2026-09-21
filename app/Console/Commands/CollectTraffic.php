<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Cron\ServerZone;
use App\Support\Metrics\Daily;
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

    public function handle(Client $agent, Daily $daily): int
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

        /*
         * **Welcher Tag läuft, fragt {@see ServerZone} und nicht dieses Panel.**
         *
         * `config/app.php` steht fest auf `UTC`; ein Server in `+0200` ist um
         * 01:30 Ortszeit für `now()` noch im Vortag. Der gestrige Tag trägt in
         * den Protokollen dasselbe Datum und landete damit jede Nacht unter
         * „noch offen" — gezählt würde nie etwas, und dieser Lauf bliebe dabei
         * grün.
         *
         * > **Wer entscheidet, ob ein Tag vorbei ist, muss die Uhr lesen, die
         * > ihn geschrieben hat.**
         *
         * Geschrieben hat ihn nginx, mit der Ortszeit des Systems — und die
         * beantwortet in diesem Panel genau eine Klasse. Der erste Wurf dieses
         * Laufs hat sie im Agenten **noch einmal** gebaut;
         * `ServerZoneSourceTest` hat es angehalten.
         *
         * > **Eine zweite Fassung derselben Frage ist die, die veraltet.**
         *
         * `current()` gibt `null`, wenn die Datei nicht lesbar ist. Dann wird
         * **nicht** auf `now()` zurückgefallen: Ein Notnagel, der stillschweigend
         * die falsche Uhr nimmt, ist genau der Fehler, gegen den diese Zeile
         * steht.
         */
        $zone = ServerZone::current();

        if ($zone === null) {
            $this->error('  Die Zeitzone des Servers ist nicht lesbar — ohne sie wäre jeder Tag geraten.');

            return self::FAILURE;
        }

        $today = now()->setTimezone($zone)->toDateString();

        $this->line(sprintf('  Laufender Tag auf dem Server: %s (%s).', $today, $zone->getName()));

        $totals = is_array($result['totals'] ?? null) ? $result['totals'] : [];
        $split = AccessCounts::split($result, $today);

        $this->line(sprintf(
            '  Laufender Tag auf dem Server: %s (%s).',
            $today,
            is_string($result['timezone'] ?? null) ? $result['timezone'] : 'Zeitzone unbekannt',
        ));

        $this->line(sprintf(
            '  %d Domain(s) gelesen, %d Zeile(n), davon %d gedeutet, %d aus dem alten Zeitalter, %d unlesbar.',
            (int) ($totals['domains'] ?? 0),
            (int) ($totals['lines'] ?? 0),
            (int) ($totals['parsed'] ?? 0),
            (int) ($totals['legacy'] ?? 0),
            (int) ($totals['unreadable'] ?? 0),
        ));

        $this->line(sprintf(
            '  %d Tageswert(e) zählbar, %d übersprungen (gemischtes Format), %d noch offen (laufender Tag).',
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

        /*
         * **Erst ablegen, dann abräumen.** Andersherum nähme der Lauf einer
         * frisch geschriebenen Zeile ihren Tag weg, sobald die Aufbewahrung
         * genau auf ihn fällt — ein Fehler, der nur einmal im Monat sichtbar
         * wäre und dann wie ein verlorener Tag aussähe.
         */
        $geschrieben = $daily->record($split['countable']);

        $this->line(sprintf(
            '  Abgelegt: %d Zeile(n) je Domain, %d je Abonnement.',
            $geschrieben['domains'],
            $geschrieben['subscriptions'],
        ));

        /*
         * **Ein Verzeichnis ohne Zeile wird benannt und nicht übergangen.**
         * Es ist entweder ein Rest eines Rückbaus oder ein Abonnement, das dem
         * Panel fehlt — und beides sieht in einer Summe wie „nichts" aus.
         */
        foreach ($geschrieben['unknown'] as $eintrag) {
            $this->warn(sprintf(
                '  ohne Zeile im Panel: %s / %s — gezählt, aber nirgends abgelegt.',
                $eintrag['subscription'],
                $eintrag['domain'],
            ));
        }

        $abgeraeumt = $daily->forget($today);

        if ($abgeraeumt['subscriptions'] > 0 || $abgeraeumt['domains'] > 0) {
            $this->line(sprintf(
                '  Älter als %d Tage entfernt: %d je Domain, %d je Abonnement.',
                Daily::RETENTION_DAYS,
                $abgeraeumt['domains'],
                $abgeraeumt['subscriptions'],
            ));
        }

        $this->info(sprintf('  Fertig in %d ms.', (int) ($result['duration_ms'] ?? 0)));

        return self::SUCCESS;
    }
}
