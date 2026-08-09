<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Clusters;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;

/**
 * PostgreSQL installieren — oder den vorhandenen Cluster starten.
 *
 * **Die Vorlage ist {@see PhpVersionInstall}**, und mit ihr die fünf Dinge, die
 * dort richtig sind: kein Freitext an apt, wiederholbar, Erfolg **gelesen**
 * statt geglaubt, die Vorgabe der Distribution nicht überfahren, Bestand
 * erkannt.
 *
 * ## Was diese Operation tut, hängt davon ab, was sie vorfindet
 *
 * {@see Server::describe()} beantwortet das vor jedem Handgriff, und jeder
 * Zustand hat genau einen (`docs/38 §2.2d`):
 *
 * | vorgefunden | was geschieht |
 * |---|---|
 * | `absent` | `apt-get install postgresql` |
 * | `stopped` | der vorhandene Cluster wird gestartet |
 * | `ready`, `not_handed_over`, `unusable` | nichts — PostgreSQL ist da |
 * | `no_cluster` | **abgewiesen**, mit dem Befehl im Klartext |
 * | `ambiguous` | **abgewiesen** — das Panel wählt nicht |
 *
 * **Warum `no_cluster` nicht behoben wird.** Nach einer frischen Installation
 * gibt es immer einen Cluster; das Paket legt ihn im `postinst` an. Keinen zu
 * haben heisst deshalb, dass jemand ihn entfernt hat — und einen neuen daneben
 * zu stellen, ohne zu wissen warum, ist keine Reparatur, sondern eine zweite
 * Meinung. Der Befehl steht in der Antwort; ausgeführt wird er von dem, der
 * weiss, was er beim ersten Mal gemeint hat.
 *
 * **Warum `ambiguous` abgewiesen wird.** Das ist die eine Stelle, an der Raten
 * Kundendaten kostet: Zwei laufende Cluster heissen fast immer, dass der
 * Betreiber einen davon selbst betreibt.
 *
 * ## Die Übergabe ist nicht Sache dieser Operation
 *
 * Nach der Installation läuft PostgreSQL, und die Rolle `root` gibt es nicht —
 * `state` ist dann `not_handed_over`, und das ist **kein Fehlschlag**. Der
 * Agent kann sie nicht anlegen: Dafür bräuchte er genau die Verbindung, die
 * ihm noch fehlt (`docs/38 §6.1`). Das Panel zeigt den Befehl an, der Betreiber
 * führt ihn aus.
 */
final class PgServerInstall implements Op
{
    /**
     * Was installiert wird.
     *
     * **Ein Name aus einer Konstanten, kein zusammengesetzter.** Das Metapaket
     * ohne Fassungszahl zeigt auf die, die die Distribution vorsieht — auf
     * Ubuntu 24.04 ist das 16 (gemessen, `docs/38 §2.2c`). Eine Zahl hier wäre
     * eine Zusage über den Paketbestand der Distribution, die dieses Paket
     * nicht einlösen kann; sie aus einem Wert zu bauen wäre genau das, was eine
     * Positivliste verhindert (`PhpVersionInstall`).
     */
    private const PACKAGE = 'postgresql';

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
        private readonly Clusters $clusters = new Clusters,
    ) {}

    public static function name(): string
    {
        return 'pg.server.install';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $context->progress(5, 'nachsehen, was da ist');
        $before = $this->server->describe($context, $this->session);

        $done = match ($before['state']) {
            'absent' => $this->install($context),
            'stopped' => $this->start($context, $before['clusters']),
            'no_cluster' => throw AgentException::denied(
                'PostgreSQL ist installiert, aber es gibt keinen Cluster. Nach einer frischen Installation '
                .'legt das Paket einen an — es hat also jemand einen entfernt. Anlegen lässt er sich mit '
                .'`pg_createcluster <fassung> main --start`, und wer das tut, sollte wissen, warum der alte '
                .'fort ist.',
            ),
            'ambiguous' => throw AgentException::denied((string) $before['reason']),
            default => false,
        };

        /*
         * **Der Erfolg wird gelesen und nicht geglaubt.** `apt-get` meldet
         * Erfolg auch dann, wenn danach kein Cluster läuft — und
         * `PhpVersionInstall` hält denselben Satz fest, weil ein Prüfer die
         * zweite Frage sonst für toten Code hält: Über `describe()` steht
         * dieselbe Prüfung als *andere* Frage da. Taucht PostgreSQL jetzt auf?
         */
        $context->progress(90, 'nachsehen, was daraus geworden ist');
        $after = $this->server->describe($context, $this->session);

        if ($after['state'] === 'absent') {
            throw AgentException::execFailed(
                'apt meldet Erfolg, PostgreSQL fehlt trotzdem.',
                ['package' => self::PACKAGE],
            );
        }

        $context->progress(100, 'fertig');

        return [
            'changed' => $done,
            'state' => $after['state'],
            'cluster' => $after['cluster'],
            'port' => $after['port'],
            'version' => $after['version'],

            // **Die Übergabe steht in der Antwort, auch wenn sie noch aussteht.**
            // Das Panel zeigt sie an; sie hier zu verschweigen hiesse, dem
            // Betreiber nach einer erfolgreichen Installation zu sagen, es sei
            // alles fertig, während nichts geht.
            'handed_over' => $after['handed_over'],
            'handover' => $after['handover'],
        ];
    }

    private function install(Context $context): bool
    {
        $context->progress(15, 'Paketlisten auffrischen');
        $update = $context->stream('apt-get', ['update', '-qq'], 300);

        if (! $update->successful()) {
            throw AgentException::execFailed('apt-get update ist fehlgeschlagen: '.$update->message());
        }

        $context->progress(30, 'PostgreSQL installieren');
        $install = $context->stream(
            'apt-get',
            ['install', '-y', '--no-install-recommends', self::PACKAGE],
            900,
        );

        if (! $install->successful()) {
            throw AgentException::execFailed(
                'Die Installation ist fehlgeschlagen: '.$install->message(),
                ['package' => self::PACKAGE],
            );
        }

        return true;
    }

    /**
     * **Die Liste kommt aus {@see Server::describe()} und wird nicht neu
     * geholt.** `pg_lsclusters` ist eben gelaufen; ein zweiter Aufruf wäre
     * derselbe Befund mit einer weiteren Gelegenheit, davon abzuweichen — und
     * genau solche zwei Fassungen derselben Frage sind hier schon dreimal
     * auseinandergelaufen.
     *
     * @param  list<array{version: int, name: string, port: int, running: bool, directory: string}>  $clusters
     */
    private function start(Context $context, array $clusters): bool
    {
        $first = $clusters[0] ?? null;

        if ($first === null) {
            // Unerreichbar, solange `describe()` `stopped` nur bei mindestens
            // einem Cluster meldet — und trotzdem geschrieben, weil die
            // Alternative ein stiller Griff ins Leere wäre, wenn sich das
            // ändert.
            throw AgentException::execFailed('Zwischen Nachsehen und Starten ist der Cluster verschwunden.');
        }

        $context->progress(40, sprintf('Cluster %d/%s starten', $first['version'], $first['name']));
        $this->clusters->start($context, $first['version'], $first['name']);

        return true;
    }
}
