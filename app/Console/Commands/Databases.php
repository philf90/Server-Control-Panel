<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Support\Databases\DatabasePrune;
use App\Support\Databases\DumpIntegrity;
use App\Support\Databases\RemoteAccess;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\DbRemoteAccess;
use SrvPanel\Agent\Pg\Server;
use Throwable;

/**
 * Der Datenbankserver von der Kommandozeile — nachsehen und aufräumen.
 *
 * **Zwei Dinge, und beide gehören auf dieselbe Seite**, weil beide dieselbe
 * Frage beantworten: Was liegt auf diesem Server, das das Panel angeht?
 *
 * `srvpanel db` liest — Version, Geschmacksrichtung, Horchadresse, der Bestand
 * des Panels, und was ein misslungener Rückbau liegengelassen hat.
 * `srvpanel db --prune` räumt es weg, nach Rückfrage und in der Reihenfolge, in
 * der es sicher ist.
 *
 * **Warum es das überhaupt gibt.** `docs/35` hat freigelegt, dass sich in
 * diesem System ein Zertifikat nie löschen liess — ein Jahr lang, weil `create`
 * zuerst gebaut wurde und danach funktionierte. P5 legt drei weitere Dinge auf
 * dem System ab: Schemata, Datenbankbenutzer und Sicherungsdateien. Der Weg
 * zurück ist deshalb Teil desselben Beitrags und nicht die Nacharbeit, an die
 * ein Jahr später niemand denkt.
 *
 * **`--remote=on|off` ist der Schalter aus `docs/36 §12`**, und er steht hier
 * und nicht im Panel: Er lässt einen Dienst auf einer erreichbaren Adresse
 * horchen, und das ist eine serverweite Änderung. Leitbild 1 — „der Bestand ist
 * Gesetz" — verbietet, sie nebenbei zu tun, weil ein Kunde ein Häkchen gesetzt
 * hat. Erst wenn der Server tatsächlich horcht, bietet das Panel einem Kunden
 * überhaupt an, einen Zugang für eine fremde Adresse anzulegen.
 *
 * **Und der Neustart wird angesagt, bevor er passiert.** Der Datenbankserver
 * trägt auch das Panel; ein `--remote` ohne Rückfrage wäre eine Unterbrechung,
 * die niemand erwartet hat.
 */
final class Databases extends Command
{
    protected $signature = 'srvpanel:db
        {--prune : Reste eines misslungenen Rückbaus entfernen — Schemata, Zugänge und Sicherungen}
        {--dry-run : Mit --prune: nur zeigen, was entfernt würde}
        {--remote= : on oder off — ob der Datenbankserver auf einer erreichbaren Adresse horcht}
        {--bind=0.0.0.0 : Mit --remote=on: * für beide Stapel, 0.0.0.0 nur IPv4, :: nur IPv6}
        {--postgresql= : on oder off — ob das Panel PostgreSQL-Datenbanken anbietet}';

    protected $description = 'Zeigt den Datenbankserver und räumt auf, was ein Rückbau liegenliess';

    public function handle(Client $agent, DatabasePrune $prune, Tenancy $tenancy, Settings $settings): int
    {
        if ($this->option('prune') === true) {
            return $this->prune($agent, $prune);
        }

        if ($this->option('remote') !== null) {
            return $this->remote($agent, $tenancy);
        }

        if ($this->option('postgresql') !== null) {
            return $this->postgres($agent, $settings, $tenancy);
        }

        return $this->showServer($agent, $prune, $tenancy);
    }

    /**
     * Nachsehen — der Server und der Bestand.
     *
     * **Der Server wird gefragt und nicht das Panel.** Was hier interessiert,
     * ist der Unterschied zwischen beiden: Ein Schema, das im Panel steht und
     * auf dem Server fehlt, ist eine andere Lage als eines, das der Server hat
     * und das Panel nicht. Die erste Frage beantwortet `db.server.info`, die
     * zweite `srvpanel db --prune`.
     */
    private function showServer(Client $agent, DatabasePrune $prune, Tenancy $tenancy): int
    {
        /*
         * **Ein toter Agent beendet dieses Kommando nicht mehr.** Hier stand
         * ein `return self::FAILURE`, und damit war alles Weitere unerreichbar
         * — auch das, was das Panel ganz allein beantworten kann: sein eigener
         * Bestand und die Frage, ob die Sicherungen noch zu ihren Dateien
         * passen. Wer nachsieht, weil etwas kaputt ist, bekommt jetzt beides
         * statt einer Zeile. Der Rückgabewert bleibt trotzdem 1.
         */
        $info = [];
        $agentFehlt = false;

        try {
            $info = $agent->call('db.server.info', [], $this->actor());
        } catch (AgentException $error) {
            $this->error('Der Agent hat abgewiesen: '.$error->getMessage());
            $agentFehlt = true;
        }

        if (($info['available'] ?? false) !== true) {
            $this->warn('Kein Datenbankserver erreichbar: '.($info['reason'] ?? 'kein Grund genannt'));
        } else {
            $this->line(sprintf(
                '%s %s — %s',
                $info['flavour'] ?? 'unbekannt',
                $info['version'] ?? '?',
                ($info['usable'] ?? false) === true
                    ? 'nutzbar'
                    : 'nicht nutzbar: '.($info['reason'] ?? 'kein Grund genannt'),
            ));

            $this->line(sprintf(
                'Horcht auf %s — Fernzugriff %s.',
                $info['bind_address'] ?? 'unbekannt',
                ($info['remote'] ?? false) === true ? 'möglich' : 'aus',
            ));
        }

        /*
         * **Befristete Zugänge, die stehengeblieben sind.** Das Zurückspielen
         * legt einen an und entfernt ihn im `finally`; ein abgebrochener Lauf —
         * Stromausfall, SIGKILL — kann trotzdem einen zurücklassen, und das
         * wäre ein Zugang ohne Besitzer. Der Agent meldet nur solche, die älter
         * als eine Stunde sind (`DbServerInfo::GRACE_SECONDS`) — einer von vor
         * fünf Minuten gehört sehr wahrscheinlich zu einem Lauf, der gerade
         * arbeitet.
         */
        $this->reportStale(
            is_array($info['stale_users'] ?? null) ? $info['stale_users'] : [],
            'MariaDB',
        );

        /*
         * **Und dasselbe für PostgreSQL.** Hier stand nichts, und die Ausgabe
         * dieses Kommandos endete trotzdem mit „Nichts liegengeblieben." —
         * über eine Fläche, die sie nie angesehen hat. Gefunden hat es der
         * Abnahmelauf am 10. August 2026, weil Punkt 7f seine Reste von Hand
         * mit `SELECT … FROM pg_roles` zählen musste (`docs/39 §12a`).
         *
         * > **Ein Werkzeug, das Entwarnung gibt, muss die ganze Fläche sehen
         * > können, über die es Entwarnung gibt.**
         *
         * `stale_roles` gab es im Agenten seit Schritt 6 — gerechnet und von
         * niemandem gelesen. Das ist die stillere Schwester des Musters, für
         * das es `AgentOperationReachTest` gibt: Dort wird eine Operation nicht
         * gerufen, hier wird ihre Antwort nicht gelesen.
         */
        $this->reportPostgres($agent);

        /*
         * **Der Bestand steht hinter beiden Servern und nicht unter einem.**
         * Diese Zahlen zählten schon immer über beide Systeme — gedruckt wurden
         * sie aber unmittelbar unter dem MariaDB-Block. Auf `cloudsrv24` hielt
         * MariaDB nichts und PostgreSQL alles, und die Zeile las sich trotzdem
         * wie eine Auskunft über MariaDB (`docs/45 §5`).
         *
         * > **Eine Zahl erbt die Überschrift, unter der sie steht.**
         */
        $this->reportInventory($tenancy);

        $this->reportDumpSizes($tenancy);

        $plan = $prune->plan();

        /*
         * **Die Entwarnung nennt, worüber sie Entwarnung gibt.** „Nichts
         * liegengeblieben." stand in Grün unmittelbar unter einer orangen
         * Meldung über befristete Zugänge, die stehengeblieben sind — beide
         * Aussagen stimmen und meinen Verschiedenes, aber nebeneinander gelesen
         * widersprechen sie sich (`docs/45 §5`). Diese Zeile hier redet allein
         * über Bestand ohne Abonnement; was auf dem Server steht, hat
         * {@see self::reportStale()} zwei Zeilen darüber beantwortet.
         *
         * > **Eine Entwarnung ohne Umfang wird als die grössere gelesen, die
         * > sie ist.**
         */
        if ($plan['total'] === 0) {
            $this->info('Keine Zeile ohne Abonnement.');
        }

        if ($plan['total'] > 0) {
            $this->warn(sprintf('%d Zeile(n) ohne Abonnement — siehe `srvpanel db --prune`.', $plan['total']));
            $this->printPlan($plan);
        }

        return $agentFehlt ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Was der Bestand über eine Sicherung sagt, gegen die Datei gehalten.
     *
     * **Der Anlass ist eine Zahl, die niemand je geprüft hätte.** Am 9. August
     * 2026 stand auf `cloudsrv24` in `database_dumps.bytes` für eine Sicherung
     * 69255, auf der Platte lagen 69362 — und `bytes` ist genau die Zahl, die
     * dem Kunden als „Grösse" angezeigt wird. Woher die Abweichung dieser einen
     * Zeile kam, war nicht mehr zu klären; **der Fund ist, dass es keine Rolle
     * spielte, weil nichts die beiden je gegeneinander hielt**
     * (`docs/36 §22.3w`).
     *
     * Dieselbe Familie wie das `GRANT`, das sein Schema überlebte, und die
     * Zeile, die ihre Datei überlebte: eine Angabe im Bestand, die auf etwas
     * ausserhalb zeigt, ohne dass jemand nachsieht. Keinen der drei hat ein
     * Test gefunden, sondern ein Abnahmelauf.
     *
     * **Gemeldet und nicht repariert.** Was hier auffällt, ist entweder eine
     * falsche Zahl oder eine fehlende Datei — das eine ein Schönheitsfehler,
     * das andere ein Datenverlust. Wer löscht, weiss, was er löscht; dieselbe
     * Entscheidung wie bei den befristeten Zugängen oben.
     */
    private function reportDumpSizes(Tenancy $tenancy): void
    {
        /** @var list<array{name: string, grund: string}> $befunde */
        $befunde = [];
        $geprueft = 0;

        /** @var list<DatabaseDump> $dumps */
        $dumps = $tenancy->withoutRestriction(
            static fn (): array => DatabaseDump::query()->with('subscription')->get()->all()
        );

        foreach ($dumps as $dump) {
            // Nur fertige: Eine Sicherung, die noch läuft oder gescheitert ist,
            // hat noch keine Datei. Sie hier zu melden wäre eine Warnung für den
            // Regelfall, und die liest nach zwei Tagen niemand mehr.
            if (! $dump->status->usable()) {
                continue;
            }

            $path = $dump->path();

            if ($path === null) {
                continue;
            }

            $geprueft++;

            $grund = DumpIntegrity::reason($dump->bytes === null ? null : (int) $dump->bytes, $path);

            if ($grund !== null) {
                $befunde[] = ['name' => $dump->storage_name, 'grund' => $grund];
            }
        }

        if ($befunde === []) {
            if ($geprueft > 0) {
                $this->line(sprintf('%d Sicherung(en) geprüft: Grösse und Datei stimmen überein.', $geprueft));
            }

            return;
        }

        $this->warn(sprintf('%d von %d Sicherung(en) weichen von ihrer Datei ab:', count($befunde), $geprueft));

        foreach ($befunde as $befund) {
            $this->line(sprintf('  %s — %s', $befund['name'], $befund['grund']));
        }
    }

    /**
     * Aufräumen — erst auf dem System, dann im Bestand.
     *
     * **Die Reihenfolge ist dieselbe wie bei `srvpanel tls --prune`, und aus
     * demselben Grund:** Bricht der Agent ab, bleibt die Zeile stehen und zeigt
     * weiter auf das, was sie meint — ein zweiter Lauf holt es nach.
     * Andersherum wäre das Schema danach unauffindbar, und es läge mit den
     * Daten eines Kunden in `/var/lib/mysql`, ohne dass noch irgendetwas darauf
     * zeigt.
     *
     * **Zugänge vor Schemata.** Ein Zugang, dessen Schema schon weg ist, ist
     * ein Zugang auf nichts; ein Schema, dessen Zugang noch da ist, ist ein
     * offener Weg zu Daten. Von den beiden Zwischenzuständen ist der erste der
     * harmlosere.
     */
    private function prune(Client $agent, DatabasePrune $prune): int
    {
        $plan = $prune->plan();

        // Dieselbe Auskunft wie in der Übersicht, und deshalb derselbe Satz:
        // Dieses Kommando räumt Bestand ohne Abonnement ab und nichts sonst.
        if ($plan['total'] === 0) {
            $this->info('Keine Zeile ohne Abonnement.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d Zeile(n) ohne Abonnement:', $plan['total']));
        $this->printPlan($plan);

        if ($this->option('dry-run') === true) {
            $this->info('--dry-run: es wurde nichts angefasst.');

            return self::SUCCESS;
        }

        /*
         * Vorgabe `false`: Ohne Rückfrage — etwa unter `--no-interaction` —
         * wird nichts gelöscht. Bei einem Kommando, das die Daten eines Kunden
         * von der Platte nimmt, ist das die richtige Richtung. Wortgleich zu
         * `srvpanel tls --prune`.
         */
        if (! $this->confirm('Diese Reste entfernen? Der Vorgang ist nicht rückgängig zu machen.', false)) {
            $this->line('Abgebrochen.');

            return self::SUCCESS;
        }

        $failed = 0;

        /*
         * **Die Operation hängt am System der Zeile, nicht am Kommando.**
         * Bis zum Abnahmelauf von P5b standen hier drei feste Namen, alle drei
         * für MariaDB. Eine liegengebliebene PostgreSQL-Rolle wäre damit an
         * `db.user.remove` gegangen — der Agent hätte sie an
         * `Db\Names::existing()` abgewiesen, und mit ihr den ganzen Lauf. Der
         * Rest bliebe liegen, und das Kommando meldete einen Fehlschlag über
         * etwas, das es nie konnte.
         *
         * `match` und keine Vorgabe: Kommt ein drittes System dazu, will ich
         * hier einen Fehler und keine stille Einordnung unter MariaDB.
         */
        foreach ($plan['users'] as $user) {
            [$operation, $arguments] = match ($user['engine']) {
                DatabaseEngine::MariaDb => ['db.user.remove', [
                    'name' => $user['name'],
                    'host' => $user['host'],
                    'user' => $this->prefixOf($user['name']),
                ]],
                DatabaseEngine::Postgres => ['pg.role.remove', [
                    'name' => $user['name'],
                    'prefix' => $this->prefixOf($user['name']),

                    // **Leer, und das ist gemessen.** `DROP OWNED BY` braucht
                    // es je Datenbank — nur gibt es die hier nicht mehr, sonst
                    // stünde die Rolle nicht ohne Abonnement da. Nach einem
                    // `DROP DATABASE` hängt an ihr nichts mehr (`docs/38 §15`),
                    // und `DROP ROLE` geht ohne Vorarbeit. Bleibt doch etwas
                    // hängen, sagt es der Agent und die Zeile bleibt stehen.
                    'databases' => [],
                ]],
            };

            $failed += $this->removeOne(
                $agent,
                $operation,
                $arguments,
                $user['name'].($user['engine'] === DatabaseEngine::MariaDb ? '@'.$user['host'] : ''),
                fn (): int => $prune->forgetUser($user['id']),
            );
        }

        foreach ($plan['databases'] as $database) {
            [$operation, $arguments] = match ($database['engine']) {
                DatabaseEngine::MariaDb => ['db.database.remove', [
                    'name' => $database['name'],
                    'user' => $this->prefixOf($database['name']),
                ]],
                DatabaseEngine::Postgres => ['pg.database.remove', [
                    'name' => $database['name'],
                    'prefix' => $this->prefixOf($database['name']),
                    'roles' => [],
                ]],
            };

            $failed += $this->removeOne(
                $agent,
                $operation,
                $arguments,
                $database['name'],
                fn (): int => $prune->forgetDatabase($database['id']),
            );
        }

        /*
         * **Die Sicherungen gehen weiter über `db.dump.remove`, für beide
         * Systeme.** Ein Dump ist eine Datei in `/var/lib/srvpanel/dumps`, und
         * die Operation kennt kein SQL — hier wäre eine Verzweigung nach
         * `engine` die zweite Fassung derselben Sache. `docs/39 §12` hält
         * fest, dass genau diese Einschränkung in Schritt 6 **entfernt** wurde.
         */
        foreach ($plan['dumps'] as $dump) {
            $failed += $this->removeOne(
                $agent,
                'db.dump.remove',
                ['subscription' => $dump['subscription'], 'storage' => $dump['name']],
                $dump['name'],
                fn (): int => $prune->forgetDump($dump['id']),
            );
        }

        if ($failed > 0) {
            $this->error(sprintf('%d Rest(e) blieben liegen — ein zweiter Lauf holt sie nach.', $failed));

            return self::FAILURE;
        }

        $this->info('Aufgeräumt.');

        return self::SUCCESS;
    }

    /**
     * Was das Panel führt — je Datenbanksystem einzeln.
     *
     * **Die Summe war die falsche Antwort.** Diese drei Zahlen zählten schon
     * immer über beide Systeme, standen aber unmittelbar unter dem
     * MariaDB-Block. Auf `cloudsrv24` hielt MariaDB nichts und PostgreSQL
     * alles — die Zeile las sich trotzdem wie eine Auskunft über MariaDB
     * (`docs/45 §5`).
     *
     * > **Eine Zahl erbt die Überschrift, unter der sie steht.**
     *
     * Sie steht deshalb hinter beiden Serverblöcken und nennt je System seine
     * eigenen. Wer hier nachsieht, fragt nach einem der beiden — und eine
     * Summe beantwortet diese Frage nicht.
     *
     * **Ohne Mandantenklammer, und ausdrücklich.** Auf der Kommandozeile ist
     * niemand angemeldet; der Grundzustand der Klammer ist „nichts", und damit
     * zählte dieses Kommando null Datenbanken auf einem Server voller
     * Datenbanken. Dieselbe Stelle, derselbe Name wie in
     * `Lifecycle::afterSuccess()` und `Usage::apply()`.
     */
    private function reportInventory(Tenancy $tenancy): void
    {
        /** @var array<string, array{databases: int, users: int, dumps: int}> $bestand */
        $bestand = $tenancy->withoutRestriction(static function (): array {
            $zahlen = [];

            foreach (DatabaseEngine::cases() as $engine) {
                $zahlen[$engine->value] = [
                    'databases' => Database::query()->where('engine', $engine)->count(),
                    'users' => DbUser::query()->where('engine', $engine)->count(),
                    'dumps' => DatabaseDump::query()->where('engine', $engine)->count(),
                ];
            }

            return $zahlen;
        });

        $this->line('Im Bestand des Panels:');

        foreach (DatabaseEngine::cases() as $engine) {
            $zahlen = $bestand[$engine->value];

            $this->line(sprintf(
                '  %-11s %d Datenbank(en), %d Zugang/Zugänge, %d Sicherung(en)',
                $engine->label(),
                $zahlen['databases'],
                $zahlen['users'],
                $zahlen['dumps'],
            ));
        }
    }

    /**
     * Befristete Zugänge, die stehengeblieben sind — für ein System.
     *
     * **Das Zurückspielen legt einen an und entfernt ihn im `finally`;** ein
     * abgebrochener Lauf — Stromausfall, SIGKILL — kann trotzdem einen
     * zurücklassen, und das wäre ein Zugang ohne Besitzer. Beide Agenten melden
     * nur solche, die älter als eine Stunde sind: Einer von vor fünf Minuten
     * gehört sehr wahrscheinlich zu einem Lauf, der gerade arbeitet.
     *
     * **Herausgelöst, weil es die Frage zweimal gibt.** Zwei Fassungen
     * derselben Schleife hiessen: eine davon wird beim nächsten Mal nachgezogen,
     * und erfahrungsgemäss ist es nicht beide.
     *
     * @param  list<mixed>  $stale
     */
    private function reportStale(array $stale, string $system): void
    {
        if ($stale === []) {
            return;
        }

        $this->warn(sprintf(
            '%d befristete(r) Zugang/Zugänge blieben in %s stehen:',
            count($stale),
            $system,
        ));

        foreach ($stale as $entry) {
            $this->line('  '.(is_string($entry) ? $entry : json_encode($entry)));
        }

        $this->line('Sie gehen mit `srvpanel db --prune`.');
    }

    /**
     * Der PostgreSQL-Cluster — Zustand und Reste.
     *
     * **Ohne Abbruch, wenn es ihn nicht gibt.** Auf einem Server ohne
     * PostgreSQL soll dieses Kommando nicht scheitern und auch nicht warnen:
     * `absent` ist dort der richtige Zustand und keine Auffälligkeit. Gemeldet
     * wird er trotzdem — wer nachsieht, will wissen, was da ist und was nicht.
     */
    private function reportPostgres(Client $agent): void
    {
        try {
            $info = $agent->call('pg.server.info', [], $this->actor());
        } catch (AgentException $error) {
            $this->warn('PostgreSQL: der Agent hat abgewiesen — '.$error->getMessage());

            return;
        }

        $this->line(match ($info['state'] ?? '') {
            'ready' => sprintf('postgresql %s — nutzbar', $info['version'] ?? '?'),
            'absent' => 'postgresql — nicht installiert',
            'not_handed_over' => 'postgresql '.($info['version'] ?? '?')
                .' — die Rolle für das Panel fehlt: '.Server::HANDOVER,
            default => 'postgresql — '.($info['reason'] ?? 'unklarer Zustand'),
        });

        if (($info['state'] ?? '') === 'ready') {
            $this->line(sprintf(
                'PostgreSQL horcht auf %s — Fernzugriff %s.',
                ($info['listen_addresses'] ?? '') === '' ? 'nur dem Socket' : $info['listen_addresses'],
                ($info['remote'] ?? false) === true ? 'möglich' : 'aus',
            ));
        }

        $this->reportStale(
            is_array($info['stale_roles'] ?? null) ? $info['stale_roles'] : [],
            'PostgreSQL',
        );

        $this->reportRuleDrift(
            is_array($info['hba_rules'] ?? null) ? array_values(array_filter($info['hba_rules'], 'is_string')) : [],
        );
    }

    /**
     * Zeilen im verwalteten Block, zu denen es im Bestand nichts gibt.
     *
     * **Der Weg zurück, den PostgreSQL selbst nicht geht** (`docs/38 §14.4`).
     * Eine `pg_hba.conf`-Zeile für eine Rolle, die es nicht mehr gibt, ist dort
     * **kein Fehler** (M22): Sie bleibt liegen, `pg_hba_file_rules` schweigt,
     * und der Cluster startet damit anstandslos. Damit ist sie das Vierte, was
     * diese Stufe auf der Platte hinterlässt — neben Datenbanken, Rollen und
     * Sicherungen —, und das Einzige davon, was gar nichts von sich meldet.
     *
     * **Gemeldet und nicht gelöscht** (`docs/36 §5`). Der Regelfall, der hier
     * auftaucht, ist harmlos: `pg.role.remove` nimmt die Zeilen einer Rolle im
     * selben Vorgang mit, und was trotzdem übrigbleibt, stammt aus einem
     * abgebrochenen Rückbau oder aus einer Zeile, die jemand von Hand in den
     * Block geschrieben hat. Beides gehört angesehen und nicht weggeräumt.
     *
     * **Und seit dem 11. August 2026 wird in beide Richtungen gefragt.** Bis
     * dahin sah dieser Abgleich nur Zeilen ohne Bestand — die harmlosere
     * Hälfte. Die andere entstand im Abnahmelauf (`docs/45 §5`): Ein
     * gescheiterter Schreibvorgang liess seine Zeile im Bestand stehen, das
     * Panel zeigte „erreichbar von …", und in `pg_hba.conf` stand nichts.
     * **Gemeldet hat das niemand**, denn hier stand ein früher Ausstieg für den
     * leeren Block — also genau für den Fall, in dem der Bestand etwas führt und
     * die Datei nichts hat.
     *
     * > **Ein Abgleich, der nur eine Richtung kennt, ist eine halbe Frage.**
     *
     * @param  list<string>  $managed
     */
    private function reportRuleDrift(array $managed): void
    {
        $remote = app(RemoteAccess::class);
        $orphans = $remote->orphans($managed);
        $missing = $remote->missing($managed);

        // Kein Block und kein Bestand: Dieser Server hat keinen Fernzugriff, und
        // eine Zeile darüber wäre auf den allermeisten eine Zeile für nichts.
        if ($managed === [] && $missing === []) {
            return;
        }

        if ($orphans === [] && $missing === []) {
            $this->line(sprintf('%d Zugangsregel(n) in pg_hba.conf, alle im Bestand.', count($managed)));

            return;
        }

        if ($orphans !== []) {
            $this->warn(sprintf(
                '%d von %d Zugangsregel(n) in pg_hba.conf zeigen auf nichts im Bestand:',
                count($orphans),
                count($managed),
            ));

            foreach ($orphans as $line) {
                $this->line('  '.$line);
            }

            $this->line(
                '  Sie werden nicht von selbst entfernt. Der nächste Lauf von `srvpanel db --remote=on` '
                .'schreibt den Block neu und nimmt sie mit.',
            );
        }

        if ($missing !== []) {
            $this->warn(sprintf(
                '%d Zugangsregel(n) fehlen in pg_hba.conf, obwohl der Bestand sie führt:',
                count($missing),
            ));

            foreach ($missing as $line) {
                $this->line('  '.$line);
            }

            $this->line(
                '  Im Panel steht damit ein Netz, das niemanden hereinlässt. Der nächste Lauf von '
                .'`srvpanel db --remote=on` schreibt den Block neu und trägt sie nach.',
            );
        }
    }

    /**
     * Einen Rest entfernen: erst der Agent, dann die Zeile.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(): int  $forget
     * @return int 1, wenn er liegenblieb
     */
    private function removeOne(Client $agent, string $operation, array $arguments, string $label, callable $forget): int
    {
        try {
            $agent->call($operation, $arguments, $this->actor());
        } catch (AgentException $error) {
            $this->error("  {$label}: {$error->getMessage()}");

            return 1;
        }

        $forget();
        $this->line("  {$label}: entfernt");

        return 0;
    }

    /**
     * Der Systembenutzer aus einem Namen.
     *
     * **Er wird gebraucht, obwohl das Abonnement fort ist.** Der Agent prüft
     * jede Operation gegen das Präfix — `Names::belongsTo()` —, damit ein
     * `DROP DATABASE mysql` an dieser Zeile scheitert. Die Prüfung entfällt
     * nicht, nur weil hier niemand mehr zuständig ist; sie bekommt das Präfix
     * aus dem Namen selbst, und der stammt aus einer abgelegten Zeile, die der
     * Agent seinerzeit erzeugt hat.
     */
    private function prefixOf(string $name): string
    {
        return explode('_', $name, 2)[0];
    }

    /**
     * @param  array{
     *     databases: list<array{id: int, name: string, subscription: string, size_bytes: int|null, engine: DatabaseEngine}>,
     *     users: list<array{id: int, name: string, host: string, subscription: string, engine: DatabaseEngine}>,
     *     dumps: list<array{id: int, name: string, subscription: string, bytes: int|null}>,
     *     total: int,
     * }  $plan
     */
    private function printPlan(array $plan): void
    {
        foreach ($plan['databases'] as $database) {
            // **Das System steht mit dabei.** Wer den Plan liest, sieht sonst
            // zwei Namen derselben Form und nicht, an welchen Agenten sie
            // gleich gehen — und genau das ist die Stelle, an der dieser
            // Rückbau bis P5b falsch abgebogen ist.
            $this->line(sprintf(
                '  Datenbank %s (%s, %s, %s)',
                $database['name'],
                $database['engine']->label(),
                $database['subscription'],
                $this->size($database['size_bytes'], 'nicht gemessen'),
            ));
        }

        foreach ($plan['users'] as $user) {
            $this->line($user['engine'] === DatabaseEngine::MariaDb
                ? sprintf('  Zugang %s@%s (%s, %s)', $user['name'], $user['host'], $user['engine']->label(), $user['subscription'])
                : sprintf('  Zugang %s (%s, %s)', $user['name'], $user['engine']->label(), $user['subscription']));
        }

        foreach ($plan['dumps'] as $dump) {
            $this->line(sprintf(
                '  Sicherung %s (%s, %s)',
                $dump['name'],
                $dump['subscription'],
                $this->size($dump['bytes'], 'Grösse unbekannt'),
            ));
        }
    }

    /**
     * Eine Byte-Zahl in der Einheit, die sie noch unterscheidbar lässt.
     *
     * **Hier stand zweimal `intdiv($bytes, 1024 * 1024).' MB'`,** und das ist
     * genau die Rundung, die der dritte Abnahmelauf als Fehler gezeigt hat: Eine
     * Datenbank mit 300 KB und eine leere lesen sich beide als „0 MB". Wer diese
     * Liste ansieht, sucht meist nach etwas, das er vermisst — und dann ist der
     * Unterschied zwischen „leer" und „klein" die ganze Auskunft (docs/36
     * §22.3j).
     */
    private function size(?int $bytes, string $whenNull): string
    {
        if ($bytes === null) {
            return $whenNull;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return intdiv($bytes, 1024).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.').' GB';
    }

    /**
     * Den Fernzugriff ein- oder ausschalten (`docs/36 §12`).
     *
     * **Drei Dinge stehen vor dem Aufruf, und jedes hat einen Anlass.**
     *
     * 1. **Wer ausschaltet, erfährt vorher, wen er aussperrt.** Ein Zugang für
     *    eine fremde Adresse ist in MariaDB ein eigener Benutzer; nach
     *    `--remote=off` erreicht ihn niemand mehr, und die Anwendung dahinter
     *    steht. Die Zahl kommt aus dem Bestand des Panels — der Agent kennt sie
     *    nicht, und er soll sie auch nicht kennen. Sie darf den Rückweg aber
     *    nicht aufhalten ({@see self::foreignAccess()}).
     * 2. **Der Neustart wird angesagt.** Der Datenbankserver trägt auch das
     *    Panel. Eine Rückfrage vor einer Unterbrechung ist das Mindeste;
     *    Vorgabe `false`, wie bei `--prune`, damit `--no-interaction` nichts tut.
     * 3. **Gemeldet wird, worauf der Server danach horcht** — seine Antwort und
     *    nicht die Datei, die wir geschrieben haben. Genau hier fällt auf, wenn
     *    eine andere Include-Datei gewinnt.
     * 4. **Und danach wird gefragt, ob das Panel selbst noch hereinkommt**
     *    ({@see self::panelDatabaseUnreachable()}). Punkt 3 ist die Antwort des
     *    Servers über den Unix-Socket des Agenten; sie war am 11. August 2026
     *    auf `cloudsrv24` „Fernzugriff möglich", während das Panel schon nicht
     *    mehr verband. Kommt es nicht herein, wird zurückgenommen — der Schalter
     *    lässt kein Panel ohne Datenbank stehen, auch nicht für eine Minute.
     */
    private function remote(Client $agent, Tenancy $tenancy): int
    {
        $mode = strtolower(trim((string) $this->option('remote')));

        if (! in_array($mode, ['on', 'off'], true)) {
            $this->error('--remote erwartet on oder off.');

            return self::FAILURE;
        }

        $address = (string) $this->option('bind');

        /*
         * **Die Liste steht im Agenten und nicht hier.** Sie ein zweites Mal
         * aufzuschreiben wäre die zweite Fassung derselben Regel, und die
         * zweite ist die, die veraltet — als `*` dazukam, hätte genau das
         * gefehlt.
         */
        if ($mode === 'on' && ! in_array($address, DbRemoteAccess::ADDRESSES, true)) {
            $this->error(sprintf(
                '--bind erwartet %s. Für „von überall" ist * der richtige Wert: MariaDB bindet :: '
                .'ausschliesslich IPv6 (gemessen auf 10.11.14), und das Panel verbindet sich über '
                .'127.0.0.1 mit seiner eigenen Datenbank.',
                implode(', ', DbRemoteAccess::ADDRESSES),
            ));

            return self::FAILURE;
        }

        $fern = $mode === 'off' ? $this->foreignAccess($tenancy) : null;

        if ($fern !== null && $fern > 0) {
            $this->warn(sprintf(
                '%d Zugang/Zugänge bzw. Netz(e) sind für eine fremde Adresse eingetragen. Nach dem '
                .'Ausschalten erreicht sie niemand mehr — die Zeilen bleiben, die Verbindung nicht.',
                $fern,
            ));
        }

        $this->line($mode === 'on'
            ? sprintf('Der Datenbankserver wird auf %s horchen. Die IP-Beschränkung gilt in MariaDB '
                .'und nicht im Paketfilter — die Firewall kommt mit P9.', $address)
            : 'Der Datenbankserver wird wieder nur lokal erreichbar sein.');

        if (! $this->confirm('Dafür wird der Datenbankserver neu gestartet. Das Panel ist dabei kurz ohne Datenbank. Weiter?', false)) {
            $this->line('Abgebrochen.');

            return self::SUCCESS;
        }

        try {
            $result = $agent->call('db.remote.access', ['mode' => $mode, 'address' => $address], $this->actor());
        } catch (AgentException $error) {
            $this->error('Der Agent hat abgewiesen: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('%s: %s', $result['path'] ?? '?', $mode === 'on' ? 'geschrieben' : 'entfernt'));

        $this->line(sprintf(
            'Horcht auf %s — Fernzugriff %s.',
            $result['bind_address'] ?? 'unbekannt',
            ($result['remote'] ?? false) === true ? 'möglich' : 'aus',
        ));

        /*
         * **Die Gegenprobe, und sie ist der Grund für die Zeile darüber.** Der
         * Agent meldet, was der Server nach dem Neustart sagt. Weicht das von
         * dem ab, was gewünscht war, ist der Lauf nicht erfolgreich, sondern
         * ein Befund — meistens eine andere Include-Datei, die später gelesen
         * wird als unsere.
         */
        if ((bool) ($result['remote'] ?? false) !== ($mode === 'on')) {
            $this->error(
                'Der Server horcht nicht so, wie es angefordert wurde. Vermutlich setzt eine andere '
                .'Include-Datei die Horchadresse später — sie werden lexikalisch gelesen.',
            );

            return self::FAILURE;
        }

        if ($mode === 'on') {
            $fehlt = null;

            if ($fehlt !== null) {
                return $this->undoRemote($agent, (string) ($result['path'] ?? ''), $fehlt);
            }
        }

        return $this->remotePostgres($mode, $address);
    }

    /**
     * Wie viele Zugänge eine fremde Adresse tragen — **oder `null`, wenn es
     * gerade niemand sagen kann.**
     *
     * **Beide Systeme werden gezählt, und sie zählen verschieden.** In MariaDB
     * steht die fremde Adresse im Benutzer (`host`), in PostgreSQL in
     * `db_user_networks` — dieselbe Frage, zwei Tabellen (`docs/38 §14.3`). Nur
     * die eine zu zählen wäre eine Warnung, die die Hälfte der Ausgesperrten
     * verschweigt.
     *
     * **Und ein Fehlschlag hier hält den Rückweg nicht mehr auf.** Diese Zahl
     * stand bis zum 11. August 2026 unbedingt am Anfang von {@see
     * self::remote()} — für beide Richtungen, obwohl nur das Ausschalten sie
     * braucht. Auf `cloudsrv24` hatte gerade `--remote=on --bind=::` das Panel
     * von seiner Datenbank abgeschnitten, und `--remote=off` starb an genau
     * dieser Abfrage, bevor es zum Agenten kam:
     * `SQLSTATE[HY000] [2002] Connection refused … from `db_users``.
     *
     * > Ein Rückweg, der den Bestand braucht, ist keiner für den Fall, dass der
     * > Bestand weg ist.
     *
     * Die Warnung ist eine Höflichkeit; der Rückweg ist es nicht. Fällt die
     * eine aus, sagt sie das und lässt den anderen laufen.
     */
    private function foreignAccess(Tenancy $tenancy): ?int
    {
        try {
            return $tenancy->withoutRestriction(
                static fn (): int => DbUser::query()->where('host', '!=', 'localhost')->count()
                    + DbUserNetwork::query()->count(),
            );
        } catch (Throwable $error) {
            $this->warn(
                'Wie viele Zugänge das betrifft, steht nicht dabei — der Bestand ist nicht lesbar: '
                .$error->getMessage(),
            );

            return null;
        }
    }

    /**
     * Erreicht das Panel seine eigene Datenbank noch?
     *
     * **Der Agent kann diese Frage nicht beantworten**, und das ist keine
     * Arbeitsteilung aus Geschmack: Er spricht mit MariaDB über den
     * Unix-Socket, weil er dafür kein Passwort braucht. Über den Socket bleibt
     * ein Server erreichbar, der auf TCP niemanden mehr hereinlässt — seine
     * Gegenprobe konnte den Ausfall vom 11. August also gar nicht sehen. Hier
     * steht sie richtig, weil hier steht, über welchen Wirt und welchen Port
     * das Panel verbindet.
     *
     * **Verbunden wird neu und nicht nachgesehen.** Die vorhandene
     * PDO-Instanz hat den Neustart nicht überlebt; ohne `disconnect()` käme die
     * Antwort aus einer Leiche.
     *
     * @return string|null die Meldung des letzten Versuchs, oder `null`, wenn es geht
     */
    private function panelDatabaseUnreachable(): ?string
    {
        $letzte = 'kein Versuch';

        // Fünf Anläufe, weil systemd die Unit als aktiv meldet, bevor MariaDB
        // die erste Verbindung annimmt. Ein einziger Versuch machte aus jedem
        // langsamen Start einen Rückbau.
        for ($versuch = 1; $versuch <= 5; $versuch++) {
            try {
                DB::connection()->disconnect();
                DB::connection()->getPdo();

                return null;
            } catch (Throwable $error) {
                $letzte = $error->getMessage();
            }

            if ($versuch < 5) {
                sleep(1);
            }
        }

        return $letzte;
    }

    /**
     * Das Panel kommt nicht mehr an seine Datenbank — **also zurück, sofort.**
     *
     * Der Weg zurück geht über dieselbe Operation mit `mode = off`: Sie nimmt
     * die Include-Datei weg und startet neu. Scheitert auch das, steht der
     * Handgriff da, mit dem Pfad, den der Agent gerade gemeldet hat — wer an
     * dieser Stelle steht, hat kein Panel mehr, in dem er nachlesen könnte.
     */
    private function undoRemote(Client $agent, string $path, string $grund): int
    {
        $this->error('Das Panel erreicht seine eigene Datenbank nicht mehr: '.$grund);

        try {
            $agent->call('db.remote.access', ['mode' => 'off'], $this->actor());

            $this->line('Zurückgenommen — der Datenbankserver horcht wieder nur lokal.');
        } catch (AgentException $error) {
            $this->error(sprintf(
                'Und das Zurücksetzen ist ebenfalls gescheitert: %s — von Hand: rm -f %s && systemctl restart mariadb',
                $error->getMessage(),
                $path !== '' ? $path : '/etc/mysql/mariadb.conf.d/'.DbRemoteAccess::FILE,
            ));
        }

        return self::FAILURE;
    }

    /**
     * Und dieselbe Frage für PostgreSQL (`docs/38 §14`).
     *
     * **Ein Schalter für beide Systeme und nicht zwei.** „Der Datenbankserver
     * ist von aussen erreichbar" ist eine Aussage über den *Rechner*, und ein
     * Betreiber, der sie für MariaDB trifft und für PostgreSQL vergisst, hat
     * eine Fläche offen, von der er nichts weiss — oder eine geschlossen, die
     * er gerade aufmachen wollte. Zwei Schalter wären zwei Fassungen derselben
     * Entscheidung, und die zweite ist die, die veraltet.
     *
     * **Übersprungen wird nur, was das Panel nicht anbietet.** Ist PostgreSQL
     * abgeschaltet (`--postgresql=off`) oder nicht nutzbar, steht der Grund
     * da — als Zeile und nicht als Schweigen. Ein Kommando, das die Hälfte
     * seiner Arbeit stillschweigend auslässt, gibt Entwarnung über eine Fläche,
     * die es nicht angesehen hat; genau das war der Fund aus `docs/39 §12a`.
     *
     * **Und die Netze gehen mit.** Der Aufruf trägt den vollständigen
     * Sollzustand ({@see RemoteAccess::rules()}), damit der verwaltete Block
     * beim Einschalten sofort steht statt beim nächsten Formular eines Kunden.
     */
    private function remotePostgres(string $mode, string $address): int
    {
        $angeboten = app(Settings::class)->postgresOffered();

        /*
         * **„Nicht nachgesehen" ist kein „nein".** Diese Zeile hat am
         * 11. August 2026 auf `cloudsrv24` gemeldet, der Betreiber habe
         * PostgreSQL nicht freigeschaltet — während die Betreiberseite „Wird
         * angeboten: ja" zeigte und beide dieselbe Methode lasen. Der Unterschied
         * war, dass MariaDB zwei Zeilen weiter oben gerade neu gestartet worden
         * war und das Panel ausgesperrt hatte; der Leseversuch scheiterte, und
         * `Settings` machte daraus ein `false` (siehe
         * {@see Settings::postgresOffered()}).
         */
        if ($angeboten === null) {
            $this->error(
                'Die Einstellungen sind nicht lesbar — ob das Panel PostgreSQL anbietet, steht damit '
                .'nicht fest. MariaDB ist umgestellt, PostgreSQL nicht.',
            );

            return self::FAILURE;
        }

        if (! $angeboten) {
            $this->line('PostgreSQL: übersprungen — das Panel bietet es nicht an (srvpanel db --postgresql=on).');

            return self::SUCCESS;
        }

        /*
         * **Die Adresse geht durch und wird nicht übersetzt.** Hier stand eine
         * Umrechnung (`::` → `*`), und sie beruhte auf der Annahme, MariaDBs
         * `::` binde einen Doppelstapel. Gemessen bindet es IPv6-only — genau
         * wie PostgreSQLs `::`. Damit bedeuten die drei erlaubten Werte in
         * beiden Systemen dasselbe, und eine Zuordnung, die nichts mehr zuordnet,
         * ist nur noch eine Stelle, an der die beiden auseinanderlaufen können.
         */
        try {
            $result = app(RemoteAccess::class)->sync($mode, $mode === 'on' ? $address : null);
        } catch (AgentException $error) {
            $this->error('PostgreSQL hat abgewiesen: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'PostgreSQL horcht auf %s — Fernzugriff %s, %d Zugangsregel(n) in %s.',
            $result['listen_addresses'] ?? 'unbekannt',
            ($result['remote'] ?? false) === true ? 'möglich' : 'aus',
            (int) ($result['rule_count'] ?? 0),
            $result['hba_path'] ?? 'pg_hba.conf',
        ));

        // Dieselbe Gegenprobe wie für MariaDB darüber, und aus demselben Grund:
        // Ob der Include-Punkt greift, lässt sich nicht erfragen — nur hier
        // ablesen (`Pg\Server::includeDirectory()`).
        if ((bool) ($result['remote'] ?? false) !== ($mode === 'on')) {
            $this->error(
                'PostgreSQL horcht nicht so, wie es angefordert wurde. Vermutlich fehlt in '
                ."postgresql.conf das aktive include_dir = 'conf.d'.",
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Die PostgreSQL-Fläche freischalten oder wieder schliessen (`docs/38 §7`).
     *
     * **Dieser Schalter installiert nichts.** Er sagt, ob das Panel
     * PostgreSQL-Datenbanken *anbietet* — installiert wird über den Knopf in
     * „Einstellungen → Datenbankserver" (`docs/38 §21`, Entscheidung 10). Die
     * beiden zu trennen ist der Punkt: Ein Server kann ein PostgreSQL tragen,
     * das dem Betreiber gehört, und eine Fläche, die von selbst aufgeht, sobald
     * `pg_lsclusters` etwas findet, schriebe Kundendatenbanken in einen
     * Cluster, den niemand dafür vorgesehen hat.
     *
     * **Deshalb ist auch kein Neustart im Spiel** — und deshalb steht die
     * Installation, anders als `--remote`, hinter einem Knopf: `apt-get install
     * postgresql` fasst MariaDB nicht an, und die Anfrage, die den Vorgang
     * anstösst, verliert ihre Verbindung nicht.
     *
     * **Was beim Abschalten passiert, steht vorher da.** Vorhandene
     * PostgreSQL-Datenbanken werden nicht angerührt; sie laufen weiter, und der
     * Kunde erreicht sie mit seinen Zugangsdaten. Was verschwindet, ist die
     * Fläche im Panel. Das ist ein Unterschied, den man gesagt bekommen muss,
     * bevor man ihn macht.
     */
    private function postgres(Client $agent, Settings $settings, Tenancy $tenancy): int
    {
        $mode = strtolower(trim((string) $this->option('postgresql')));

        if (! in_array($mode, ['on', 'off'], true)) {
            $this->error('--postgresql erwartet on oder off.');

            return self::FAILURE;
        }

        $an = $mode === 'on';

        if ($an) {
            /*
             * **Der Zustand des Servers wird gezeigt und nicht geprüft.** Der
             * Schalter ist eine Absicht, und eine Absicht darf einem Zustand
             * vorausgehen: Wer erst freischaltet und dann installiert, soll
             * dabei nicht in eine Fehlermeldung laufen. Gesagt wird es
             * trotzdem, sonst steht am Ende eine offene Fläche ohne Server
             * dahinter und niemand weiss es.
             */
            try {
                $info = $agent->call('pg.server.info', [], $this->actor());

                $this->line(match ($info['state'] ?? '') {
                    'ready' => sprintf('PostgreSQL läuft: %s.', $info['version'] ?? '?'),
                    'absent' => 'PostgreSQL ist nicht installiert. Der Knopf in „Einstellungen → '
                        .'Datenbankserver" holt es nach.',
                    'not_handed_over' => 'PostgreSQL läuft, aber die Rolle für das Panel fehlt noch: '
                        .Server::HANDOVER,
                    default => 'PostgreSQL: '.($info['reason'] ?? 'unklarer Zustand'),
                });
            } catch (AgentException $error) {
                $this->warn('Der Agent hat nicht geantwortet: '.$error->getMessage());
            }
        } else {
            // Ohne Mandantenklammer, und ausdrücklich: Auf der Kommandozeile ist
            // niemand angemeldet, und der Grundzustand der Klammer ist „nichts" —
            // die Warnung zählte sonst immer null.
            $bestand = $tenancy->withoutRestriction(
                static fn (): int => Database::query()->where('engine', DatabaseEngine::Postgres)->count(),
            );

            if ($bestand > 0) {
                $this->warn(sprintf(
                    '%d PostgreSQL-Datenbank(en) stehen im Bestand. Sie bleiben, wo sie sind, und ihre '
                    .'Zugänge funktionieren weiter — im Panel sind sie danach nicht mehr zu sehen.',
                    $bestand,
                ));
            }
        }

        $settings->savePostgres($an);

        $this->line($an
            ? 'PostgreSQL wird jetzt angeboten.'
            : 'PostgreSQL wird nicht mehr angeboten.');

        return self::SUCCESS;
    }

    /**
     * Wer den Aufruf ausgelöst hat — für das Protokoll des Agenten.
     *
     * @return array<string, string>
     */
    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:db'];
    }
}
