<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseEngine;
use App\Models\DbUser;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Db\Server;

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

    public function show(Client $agent): Response
    {
        return Inertia::render('Settings/Database', [
            'server' => $this->server($agent),

            /*
             * **Wie viele Zugänge auf eine fremde Adresse lauten.** Die Zahl
             * kommt aus dem Bestand des Panels und nicht vom Datenbankserver:
             * Was hier interessiert, ist, was dieses Panel angelegt hat — ein
             * Konto, das jemand von Hand in `mysql.global_priv` geschrieben
             * hat, gehört ihm nicht und stünde hier als fremde Zahl.
             *
             * Ohne Mandantenklammer wird nichts gefragt: Diese Route trägt
             * `can:manage-settings`, und für einen Betreiber ist die Klammer
             * über `forAccount()` ohnehin offen. Ein `withoutRestriction()`
             * wäre hier eine Ausnahme ohne Anlass.
             */
            'remote_users' => $this->remoteUsers(),

            'commands' => [
                'on' => self::COMMAND_ON,
                'off' => self::COMMAND_OFF,
            ],
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
}
