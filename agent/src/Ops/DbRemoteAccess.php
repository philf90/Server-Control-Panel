<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Server;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Den Datenbankserver auf einer erreichbaren Adresse horchen lassen — oder nicht.
 *
 * **Der einzige Schritt von P5, nach dem ein Dienst von aussen erreichbar
 * ist**, und deshalb steht er hinter einem ausdrücklichen Schalter des
 * Betreibers (`docs/36 §12`, Entscheidung 5 aus §19). Ein Kunde kann ihn nicht
 * auslösen; das Panel ruft diese Operation nirgends auf. Der einzige Weg
 * hierher ist `srvpanel db --remote=on|off`.
 *
 * **Eine eigene Datei, kein Eingriff in eine fremde.** Geschrieben wird
 * `60-srvpanel.cnf` im Include-Verzeichnis der Distribution — Leitbild 1, „der
 * Bestand ist Gesetz". Die `60` ist kein Geschmack: Debian und Ubuntu legen
 * ihre `bind-address` in `50-server.cnf` ab, und MariaDB liest die Dateien in
 * lexikalischer Reihenfolge. Eine `40-` würde überschrieben und die Operation
 * meldete Erfolg, während sich nichts geändert hat.
 *
 * **Die Adresse kommt aus einer Positivliste und nicht aus dem Aufruf.** In
 * eine Konfigurationsdatei geschrieben zu werden ist genau das, wovor die
 * Positivliste des Agenten schützt; zwei erlaubte Werte reichen für die Sache
 * und lassen nichts durch.
 *
 * **Was am Ende zählt, ist die Antwort des Servers und nicht die Datei.**
 * Zurück geht die `bind_address`, die der laufende Server nach dem Neustart
 * meldet. Eine geschriebene Zeile ist eine Absicht; erst `@@bind_address` ist
 * ein Zustand — und wenn eine andere Include-Datei gewinnt, steht es hier und
 * nicht in einem Vorfallsbericht drei Wochen später.
 *
 * **Der Neustart ist die gefährliche Stelle.** Der Datenbankserver trägt auch
 * das Panel selbst: Startet er mit der neuen Datei nicht, ist nicht nur der
 * Fernzugriff aus, sondern alles. Deshalb wird der vorherige Zustand der Datei
 * gemerkt und bei einem gescheiterten Neustart wiederhergestellt — mit einem
 * zweiten Neustart hinterher, damit der Server nicht mit einer Datei steht,
 * die niemand mehr will.
 */
final class DbRemoteAccess implements Op
{
    /** Die Include-Verzeichnisse, je Geschmacksrichtung genau eines. */
    private const DIRECTORIES = [
        'mariadb' => '/etc/mysql/mariadb.conf.d',
        'mysql' => '/etc/mysql/mysql.conf.d',
    ];

    /** Die Unit, die neu gestartet wird — dieselbe Zuordnung. */
    private const UNITS = [
        'mariadb' => 'mariadb.service',
        'mysql' => 'mysql.service',
    ];

    /** Der Dateiname, in jedem Verzeichnis derselbe. */
    public const FILE = '60-srvpanel.cnf';

    /**
     * Worauf gehorcht werden darf.
     *
     * `0.0.0.0` ist die Vorgabe und deckt IPv4. `::` deckt auf einem
     * Doppelstapel beides — und scheitert auf einem Rechner, auf dem IPv6
     * abgeschaltet ist, beim Start. Deshalb ist es die ausdrückliche Wahl und
     * nicht die Vorgabe: Der Rückweg unten fängt es auf, aber ein Neustart, der
     * erst scheitert und dann zurückrollt, ist ein schlechter erster Eindruck
     * für etwas, das der Betreiber gerade eingeschaltet hat.
     */
    private const ADDRESSES = ['0.0.0.0', '::'];

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'db.remote.access';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $enabled = Guard::enum($args['mode'] ?? null, ['on', 'off'], 'mode') === 'on';
        $address = Guard::enum($args['address'] ?? '0.0.0.0', self::ADDRESSES, 'address');

        $context->progress(10, 'Datenbankserver befragen');
        $before = $this->server->describe($context, $this->session);

        if ($before['available'] !== true) {
            throw AgentException::denied(
                'Der Datenbankserver antwortet nicht: '.($before['reason'] ?? 'kein Grund genannt')
                .' — solange er steht, wird an seiner Horchadresse nichts geändert.',
            );
        }

        $flavour = $before['flavour'];

        if (! isset(self::DIRECTORIES[$flavour])) {
            throw AgentException::denied(sprintf(
                'Für %s ist kein Include-Verzeichnis bekannt; die Horchadresse bleibt, wie sie ist.',
                $flavour,
            ));
        }

        $directory = self::DIRECTORIES[$flavour];

        if (! is_dir($directory)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                $directory.' fehlt — dort erwartet dieser Server seine Include-Dateien.',
            );
        }

        $file = $directory.'/'.self::FILE;
        $vorher = is_file($file) ? (string) file_get_contents($file) : null;

        $context->progress(35, $enabled ? 'Include-Datei schreiben' : 'Include-Datei entfernen');
        $this->apply($file, $enabled ? self::content($address) : null);

        $context->progress(60, 'Datenbankserver neu starten');

        $restart = $context->runner->run('systemctl', ['restart', self::UNITS[$flavour]], 120);

        if (! $restart->successful()) {
            // Zurück auf den Stand von vorher, und dann noch einmal starten:
            // Ein Server, der nicht läuft, nimmt das Panel mit.
            $this->apply($file, $vorher);
            $context->runner->run('systemctl', ['restart', self::UNITS[$flavour]], 120);

            throw AgentException::execFailed(
                'Der Neustart ist gescheitert, die vorherige Einstellung ist wiederhergestellt: '.$restart->message(),
            );
        }

        $context->progress(85, 'nachsehen, worauf er jetzt horcht');
        $after = $this->server->describe($context, $this->session);

        $context->progress(100, 'fertig');

        return [
            'path' => $file,
            'enabled' => $enabled,
            'address' => $enabled ? $address : null,
            'bind_address' => $after['bind_address'],

            // Die Antwort des laufenden Servers, nicht die Absicht des
            // Aufrufers. Weichen die beiden ab, sieht man es genau hier.
            'remote' => $after['remote'],
            'available' => $after['available'],
        ];
    }

    /** Die Datei, wie sie aussieht — als reine Funktion, damit ein Test sie ohne Server liest. */
    public static function content(string $address): string
    {
        return "# Von srvpanel verwaltet (srvpanel db --remote). Änderungen hier werden\n"
            ."# beim nächsten Lauf überschrieben; die Datei verschwindet mit --remote=off.\n"
            ."[mysqld]\n"
            .'bind-address = '.$address."\n";
    }

    /**
     * Den Inhalt setzen — oder die Datei entfernen, wenn er `null` ist.
     *
     * Beides an einer Stelle, weil der Rückweg oben denselben Aufruf braucht:
     * Der vorherige Zustand ist entweder ein Text oder „es gab sie nicht", und
     * eine zweite Fassung dieser Fallunterscheidung wäre die, die beim
     * Zurückrollen falsch ist.
     */
    private function apply(string $file, ?string $content): void
    {
        if ($content === null) {
            if (is_file($file) && ! @unlink($file)) {
                throw AgentException::execFailed('Die Include-Datei liess sich nicht entfernen: '.$file);
            }

            return;
        }

        if (@file_put_contents($file, $content) === false) {
            throw AgentException::execFailed('Die Include-Datei liess sich nicht schreiben: '.$file);
        }

        // Lesbar für alle, wie die Nachbardateien: Sie enthält keine
        // Geheimnisse, und der Datenbankserver liest sie nicht als root.
        @chmod($file, 0o644);
    }
}
