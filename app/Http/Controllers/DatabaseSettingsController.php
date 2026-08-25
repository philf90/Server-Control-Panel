<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Support\Settings\Settings;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Db\Server;
use SrvPanel\Agent\Ops\PgServerInstall;
use SrvPanel\Agent\Pg\Server as PgServer;

/**
 * Der Datenbankserver, wie er gerade steht — nachsehen, nicht schalten.
 *
 * **Warum es diese Seite gibt.** `docs/36 §19`, Entscheidung 5, legt den
 * Fernzugriff auf `srvpanel db --remote=on` und begründet das damit, dass eine
 * serverweite Horchadresse keine Folge eines Kundenhäkchens sein darf. Das ist
 * die Regel fürs **Schalten** und war nie eine Aussage darüber, wo der Zustand
 * *steht*. Er stand nirgends: Wer im Panel wissen wollte, ob der Server nach
 * aussen horcht, musste sich auf den Server einloggen — und wer auf einer
 * Datenbankseite las „nur lokal erreichbar", hatte keinen Ort, an dem er das
 * nachprüfen konnte.
 *
 * **Und deshalb steht hier kein Schalter**, obwohl `Settings/Php.vue` zeigt,
 * dass das Panel serverweite Dinge sehr wohl über den Agenten tut. Der
 * Unterschied ist nicht die Reichweite, sondern der Neustart: `db.remote.access`
 * startet den Datenbankserver neu, und der trägt auch das Panel. Die Anfrage,
 * die den Vorgang angestossen hat, verlöre ihre Verbindung mitten im Lauf; der
 * Arbeiter, der das Ergebnis zurückschreiben soll, ebenfalls. Übrig bliebe ein
 * Vorgang, der für immer auf „läuft" steht — und zwar ausgerechnet der, nach
 * dem man wissen will, ob es geklappt hat. Auf der Kommandozeile gibt es dieses
 * Problem nicht: Dort liest `srvpanel db --remote` nach dem Neustart selbst
 * nach und scheitert, wenn der Server etwas anderes sagt als bestellt.
 *
 * **Der Zustand kommt vom Server und nicht aus einer Einstellung.** Dieselbe
 * Begründung wie bei {@see DatabaseController::remoteAccess()} — der Betreiber
 * kann `bind-address` jederzeit von Hand ändern, und eine im Panel gemerkte
 * Fassung wäre die, die veraltet. Beide Stellen lesen dieselbe Antwort des
 * Agenten; die Regel, ab wann eine Adresse „von aussen erreichbar" heisst,
 * steht nur in {@see Server::describe()} und in keiner der beiden.
 */
final class DatabaseSettingsController extends Controller
{
    /**
     * Der Befehl, der den Fernzugriff einschaltet.
     *
     * **Er steht als Konstante da, damit ein Wächter ihn lesen kann.**
     * `CommandReachTest` zerlegt jeden abgedruckten `srvpanel …`-Befehl und
     * prüft, dass es die Unterkommando-Nennung und jede Option wirklich gibt.
     * Der Anlass steht in `docs/36 §22.3v`: Auf der Datenbankseite stand seit
     * P5 `srvpanel db prune`, und das Kommando kennt kein solches Argument —
     * abgedruckt war ein Befehl, der abbricht.
     */
    public const COMMAND_ON = 'sudo srvpanel db --remote=on';

    /** Und der, der ihn wieder abschaltet. */
    public const COMMAND_OFF = 'sudo srvpanel db --remote=off';

    /**
     * Der Befehl, der die PostgreSQL-Fläche freischaltet.
     *
     * **Ein Schalter auf der Kommandozeile und ein Knopf auf dieser Seite —
     * und das ist kein Widerspruch, sondern die Trennung aus `docs/38 §7`.**
     * Freischalten heisst: Kunden dürfen PostgreSQL-Datenbanken anlegen. Das
     * ist eine Aussage über das Angebot dieses Servers und gehört dem
     * Betreiber, wie `--remote`. Installieren heisst: ein Paket der
     * Distribution kommt auf die Platte, MariaDB wird dabei nicht angefasst,
     * und niemandem bricht die Verbindung weg — deshalb darf das ein Knopf
     * sein (Entscheidung 10).
     */
    public const POSTGRES_ON = 'sudo srvpanel db --postgresql=on';

    /** Und der, der sie wieder schliesst. */
    public const POSTGRES_OFF = 'sudo srvpanel db --postgresql=off';

    public function show(Client $agent, Settings $settings): Response
    {
        return Inertia::render('Settings/Database', [
            'server' => $this->server($agent),
            'postgresql' => $this->postgres($agent, $settings),

            /*
             * **Wie viele Zugänge auf eine fremde Adresse lauten.** Die Zahl
             * kommt aus dem Bestand des Panels und nicht vom Datenbankserver:
             * Was hier interessiert, ist, was dieses Panel angelegt hat — ein
             * Konto, das jemand von Hand in `mysql.global_priv` geschrieben
             * hat, gehört ihm nicht und stünde hier als fremde Zahl.
             *
             * Ohne Mandantenklammer wird nichts gefragt: Diese Route trägt
             * `can:operate-server`, und für einen Betreiber ist die Klammer
             * über `forAccount()` ohnehin offen. Ein `withoutRestriction()`
             * wäre hier eine Ausnahme ohne Anlass.
             */
            'remote_users' => $this->remoteUsers(),

            /*
             * **Und dasselbe für PostgreSQL, getrennt gezählt.** Die beiden
             * lassen sich nicht addieren: In MariaDB ist die fremde Adresse
             * Teil des *Benutzers* (`'p1001_web'@'203.0.113.5'`), in PostgreSQL
             * ist sie eine Zeile in `pg_hba.conf` neben einer Rolle, die auch
             * ohne sie existiert (`docs/38 §14.3`). Eine gemeinsame Zahl hiesse
             * „so viele Zugänge kommen von aussen" und stimmte für keines der
             * beiden Systeme.
             */
            'remote_networks' => $this->remoteNetworks(),

            'commands' => [
                'on' => self::COMMAND_ON,
                'off' => self::COMMAND_OFF,
                'postgresql_on' => self::POSTGRES_ON,
                'postgresql_off' => self::POSTGRES_OFF,
            ],
        ]);
    }

    /**
     * Was das Panel über PostgreSQL sagen kann.
     *
     * **Zwei voneinander unabhängige Dinge, und sie stehen getrennt.**
     * `offered` ist die Absicht des Betreibers und kommt aus den Einstellungen;
     * `state` ist der Zustand des Servers und kommt bei jedem Aufruf frisch vom
     * Agenten. Sie zusammenzulegen wäre genau der Fehler, den `docs/37 §6`
     * Lehre 1 beschreibt: *Eine geschriebene Zeile ist eine Absicht, erst der
     * gelesene Zustand ist ein Zustand.*
     *
     * **Gefragt wird auch, wenn nicht freigeschaltet ist.** Der Betreiber soll
     * auf dieser Seite sehen können, was ihn erwartet, bevor er den Schalter
     * umlegt — und wer ein PostgreSQL auf dem Server hat, das ihm selbst
     * gehört, soll es hier stehen sehen statt zu raten, warum das Panel nichts
     * anbietet.
     *
     * @return array{
     *     offered: bool,
     *     reachable: bool,
     *     error: string|null,
     *     state: string,
     *     version: string|null,
     *     cluster: string|null,
     *     port: int|null,
     *     usable: bool,
     *     reason: string|null,
     *     handover: string,
     *     handed_over: bool|null,
     *     databases: int,
     *     can_install: bool,
     *     listen_addresses: string|null,
     *     remote: bool,
     * }
     */
    private function postgres(Client $agent, Settings $settings): array
    {
        $blank = [
            'offered' => $settings->postgres(),
            'reachable' => false,
            'error' => null,
            'state' => 'absent',
            'version' => null,
            'cluster' => null,
            'port' => null,
            'usable' => false,
            'reason' => null,
            'handover' => PgServer::HANDOVER,

            // Ohne Antwort des Agenten ist nichts nachgesehen — siehe unten.
            'handed_over' => null,
            'databases' => Database::query()->where('engine', DatabaseEngine::Postgres)->count(),

            // Ohne Antwort des Agenten wird nichts angeboten: Ein Knopf,
            // der auf eine Vermutung drückt, ist schlechter als keiner.
            'can_install' => false,

            /*
             * **Die Horchadresse, und sie fehlte bis zum 11. August 2026.**
             * Diese Seite fragte `db.server.info` und zeigte damit die
             * `bind-address` von *MariaDB* — über PostgreSQL stand nichts.
             * Ein Betreiber, der `srvpanel db --remote=on` gefahren hat und
             * hier nachsieht, bekam die Auskunft des anderen Servers.
             *
             * `null` heisst „nicht nachgesehen" und nicht „keine": In
             * `absent`, `stopped`, `ambiguous` und `not_handed_over` ist
             * niemand dazu gekommen, den Server zu fragen. Dieselbe
             * Dreiwertigkeit wie bei `handed_over` darüber, und aus demselben
             * Anlass.
             */
            'listen_addresses' => null,
            'remote' => false,
        ];

        try {
            $info = $agent->call('pg.server.info', []);
        } catch (AgentException $error) {
            return array_merge($blank, ['error' => $error->getMessage()]);
        }

        $state = is_string($info['state'] ?? null) ? $info['state'] : 'absent';

        return array_merge($blank, [
            'reachable' => ($info['available'] ?? false) === true,
            'state' => $state,
            'version' => is_string($info['version'] ?? null) && $info['version'] !== '' ? $info['version'] : null,
            'cluster' => is_string($info['cluster'] ?? null) ? $info['cluster'] : null,
            'port' => is_int($info['port'] ?? null) ? $info['port'] : null,
            'usable' => ($info['usable'] ?? false) === true,
            'reason' => is_string($info['reason'] ?? null) ? $info['reason'] : null,

            // Der Befehl kommt aus der Antwort und nicht aus der Konstanten
            // daneben — dieselbe Regel wie bei jedem abgedruckten Befehl
            // (`docs/36 §22.3v`): Was hier steht, soll das sein, was der Agent
            // meint, und nicht das, was das Panel darüber glaubt.
            'handover' => is_string($info['handover'] ?? null) ? $info['handover'] : PgServer::HANDOVER,
            /*
             * **Dreiwertig durchgereicht und nicht auf `false` eingeebnet.**
             * Hier stand `($info['handed_over'] ?? false) === true`, und das
             * machte aus dem `null` des Agenten — „konnte nicht nachsehen" —
             * ein `nein`. Bei gestopptem Cluster zeigte die Seite daraufhin den
             * Hinweis „Rolle anlegen" mit einem Befehl, der in diesem Zustand
             * nicht laufen kann.
             *
             * Eine fehlende Angabe ist ehrlicher als eine falsche; dieselbe
             * Entscheidung wie bei der Sortierung einer PostgreSQL-Datenbank.
             */
            'handed_over' => is_bool($info['handed_over'] ?? null) ? $info['handed_over'] : null,

            /*
             * **Die Liste gehört der Operation und nicht dieser Seite.**
             * `no_cluster` und `ambiguous` weist der Agent ab; ein Knopf
             * dafür wäre ein Knopf, der eine Fehlermeldung auslöst. Und in
             * `ready`, `not_handed_over` und `unusable` ist PostgreSQL da —
             * „installieren" hiesse dort etwas Falsches.
             */
            'can_install' => in_array($state, PgServerInstall::ACTIONABLE, true),

            /*
             * **Der Zustand kommt vom laufenden Server, nicht aus einer
             * Einstellung** — wortgleich die Begründung bei `bind_address`
             * in {@see self::server()}. Der Betreiber kann `listen_addresses`
             * jederzeit von Hand in `postgresql.conf` ändern; eine im Panel
             * gemerkte Fassung wäre die, die veraltet.
             *
             * Die leere Zeichenkette wird zu `null`: Sie heisst „nur der
             * Socket", und in einer Wertzelle in Monospace sähe sie aus wie
             * eine fehlende Angabe. Was sie bedeutet, sagt die Marke daneben.
             */
            'listen_addresses' => ($info['listen_addresses'] ?? '') === '' ? null : (string) $info['listen_addresses'],
            'remote' => ($info['remote'] ?? false) === true,
        ]);
    }

    /**
     * Was der Agent über den Datenbankserver sagt.
     *
     * **Zwei verschiedene Arten von „geht nicht", und sie stehen getrennt.**
     * `error` heisst: Der Agent hat nicht geantwortet — dann weiss diese Seite
     * gar nichts. `reason` heisst: Der Agent hat geantwortet, und die Antwort
     * war schlecht — kein Datenbankserver erreichbar, oder einer, auf dem
     * srvpanel nicht arbeitet. Zusammengelegt sähe beides gleich aus, und der
     * Unterschied ist genau der zwischen „der Agent läuft nicht" und „MariaDB
     * läuft nicht".
     *
     * @return array{
     *     reachable: bool,
     *     error: string|null,
     *     flavour_label: string,
     *     version: string|null,
     *     usable: bool,
     *     reason: string|null,
     *     bind_address: string|null,
     *     remote: bool,
     * }
     */
    private function server(Client $agent): array
    {
        try {
            $info = $agent->call('db.server.info', []);
        } catch (AgentException $error) {
            return [
                'reachable' => false,
                'error' => $error->getMessage(),
                'flavour_label' => self::FLAVOUR_UNKNOWN,
                'version' => null,
                'usable' => false,
                'reason' => null,
                'bind_address' => null,
                'remote' => false,
            ];
        }

        return [
            'reachable' => ($info['available'] ?? false) === true,
            'error' => null,
            'flavour_label' => self::flavourLabel($info['flavour'] ?? null),
            'version' => is_string($info['version'] ?? null) ? $info['version'] : null,
            'usable' => ($info['usable'] ?? false) === true,
            'reason' => is_string($info['reason'] ?? null) ? $info['reason'] : null,
            'bind_address' => is_string($info['bind_address'] ?? null) ? $info['bind_address'] : null,
            'remote' => ($info['remote'] ?? false) === true,
        ];
    }

    /** Was dasteht, wenn der Agent keine Geschmacksrichtung nennt. */
    private const FLAVOUR_UNKNOWN = '—';

    /**
     * Aus der Auskunft des Agenten wird der Text für die Oberfläche.
     *
     * **Hier und nicht im Template**, und das ist die Lehre aus `DumpKind`
     * (`docs/36 §22.3x`): Bis zum 9. August 2026 verglich `Settings/Database.vue`
     * die Zeichenkette selbst — `flavour === 'mariadb'`. Das ist ein Wert über
     * die Grenze zwischen PHP und Browser, und die prüft kein Typ: Nennt der
     * Agent seine Geschmacksrichtung eines Tages anders, steht im Panel
     * wortlos der Rohwert.
     *
     * Gefunden hat es `DatabaseEngineTest` beim ersten Lauf — er sucht nach
     * genau diesen Zeichenketten, und `mariadb` heisst an dieser Stelle etwas
     * Drittes: nicht das System einer Kundendatenbank
     * ({@see DatabaseEngine}) und nicht der Verbindungstreiber des
     * Panels, sondern was `db.server.info` aus `@@version` gelesen hat.
     */
    private static function flavourLabel(mixed $flavour): string
    {
        return match ($flavour) {
            'mariadb' => 'MariaDB',
            'mysql' => 'MySQL',
            default => self::FLAVOUR_UNKNOWN,
        };
    }

    /**
     * Die Zugänge, die auf etwas anderes als `localhost` lauten.
     *
     * **Gezählt und nach Adresse aufgeschlüsselt**, weil beide Zahlen
     * verschiedene Fragen beantworten: „wie viele Kunden hängen daran" und „von
     * wo aus". Die zweite ist die, die man beim Abschalten braucht.
     *
     * @return array{total: int, hosts: list<array{host: string, count: int}>}
     */
    private function remoteUsers(): array
    {
        $rows = DbUser::query()
            ->where('host', '!=', 'localhost')
            ->selectRaw('host, COUNT(*) as anzahl')
            ->groupBy('host')
            ->orderBy('host')
            ->get();

        $hosts = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('anzahl');
            $total += $count;
            $hosts[] = ['host' => (string) $row->getAttribute('host'), 'count' => $count];
        }

        return ['total' => $total, 'hosts' => $hosts];
    }

    /**
     * Die Netze, aus denen PostgreSQL-Zugänge hereindürfen.
     *
     * **Gezählt werden Netze und nicht Zugänge**, und das ist der Unterschied
     * zu {@see self::remoteUsers()} darüber: Eine Rolle kann mehrere Netze
     * haben — eine Rolle, ein Passwort, mehrere erlaubte Netze
     * (`docs/38 §14.3`). „Drei Netze" heisst hier also nicht „drei Zugänge".
     *
     * **Gruppiert nach Netz und nicht nach Rolle**, wie oben nach Wirt: Was
     * einen Betreiber auf dieser Seite angeht, ist, aus welchen Netzen sein
     * Server erreichbar sein soll — die Zuordnung zu einzelnen Zugängen steht
     * auf der Seite der Datenbank, wo sie hingehört.
     *
     * @return array{total: int, networks: list<array{cidr: string, count: int}>}
     */
    private function remoteNetworks(): array
    {
        $rows = DbUserNetwork::query()
            ->selectRaw('cidr, COUNT(*) as anzahl')
            ->groupBy('cidr')
            ->orderBy('cidr')
            ->get();

        $networks = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('anzahl');
            $total += $count;
            $networks[] = ['cidr' => (string) $row->getAttribute('cidr'), 'count' => $count];
        }

        return ['total' => $total, 'networks' => $networks];
    }
}
