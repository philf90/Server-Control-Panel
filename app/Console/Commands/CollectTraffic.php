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
 * **Wann er läuft, ist ihm gleichgültig — seit dem 24. September 2026
 * wirklich.** `docs/129` verlangte ursprünglich einen Timer „hinter dem von
 * `logrotate` und mit genug Abstand davor". Nachgesehen am 20. September 2026
 * auf `cloudsrv24`: `logrotate.timer` steht auf `OnCalendar=daily` mit
 * `AccuracySec=1h`. Das ist kein Zeitpunkt, sondern ein Fenster von einer
 * Stunde nach Mitternacht.
 *
 * > **Ein Abstand zu einem Zeitpunkt, den es nicht gibt, lässt sich nicht
 * > einhalten.**
 *
 * Die erste Antwort darauf las `access.log` und `access.log.1` und hielt die
 * Reihenfolge damit für gleichgültig. Das war sie nur für eine Rotation um
 * Punkt Mitternacht: Was ein Tag bis zu seiner Rotation schreibt, steht am
 * nächsten Morgen in `access.log.2.gz`, und ein Lauf, der denselben Tag in
 * der Nacht danach noch einmal sah, überschrieb die vollständige Sicht mit
 * der unvollständigen (`docs/134 §0` Punkt 2). Seitdem liest
 * `web.access.count` drei Dateien, und dieser Lauf legt **nur den Vortag** ab
 * ({@see AccessCounts::split()}) — der steht darin vollständig, gleich ob vor
 * oder nach der Rotation gezählt wird.
 *
 * **Abgelegt wird überschreibend** ({@see Daily::record()}): Derselbe Vortag
 * kann mehr als einmal vorbeikommen — ein Lauf von Hand, ein nachgeholter über
 * `Persistent=true` —, und jede dieser Sichten ist vollständig.
 *
 * **Hier stand bis dahin auch „Er legt noch nichts ab"**, geschrieben, bevor
 * es B3 gab, und stehen geblieben, als der Lauf längst `Daily::record()` rief.
 *
 * > **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
 * > nichts prüft sie.**
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
            '  %d Domain(s) gelesen, %d Zeile(n), davon %d gedeutet, %d aus dem alten Zeitalter, %d unlesbar.',
            (int) ($totals['domains'] ?? 0),
            (int) ($totals['lines'] ?? 0),
            (int) ($totals['parsed'] ?? 0),
            (int) ($totals['legacy'] ?? 0),
            (int) ($totals['unreadable'] ?? 0),
        ));

        /*
         * **Die zweite Zeile „Laufender Tag", die hier bis zum 24. September
         * 2026 stand, ist fort.** Sie las `$result['timezone']`, und das
         * schickt der Agent nicht mehr, seit die Zone aus {@see ServerZone}
         * kommt — jede Nacht stand darum `(Zeitzone unbekannt)` unter der
         * richtigen Zeile. `TrafficReportSeamTest` hält seitdem, dass dieser
         * Lauf nur liest, was die Operation schreibt.
         *
         * > **Ein Feld, das gelesen und nicht mehr geschrieben wird, liest sich
         * > als „unbekannt" — und die Zeile sieht aus wie eine Auskunft.**
         */
        $this->line(sprintf(
            '  %d Tageswert(e) vom Vortag zählbar, %d übersprungen (gemischtes Format), %d noch offen (laufender Tag), %d älter und nicht erneut abgelegt.',
            count($split['countable']),
            count($split['skipped']),
            count($split['open']),
            count($split['earlier']),
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
                '  Älter als %d Tag(e) entfernt: %d je Domain, %d je Abonnement.',
                Daily::RETENTION_DAYS,
                $abgeraeumt['domains'],
                $abgeraeumt['subscriptions'],
            ));
        }

        $this->info(sprintf('  Fertig in %d ms.', (int) ($result['duration_ms'] ?? 0)));

        return self::SUCCESS;
    }
}
