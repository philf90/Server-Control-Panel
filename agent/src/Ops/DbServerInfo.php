<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Server;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Op;

/**
 * Was für ein Datenbankserver hier läuft — und was von uns noch herumliegt.
 *
 * Zwei Auskünfte in einem Aufruf, weil beide dieselbe Verbindung brauchen und
 * beide auf dieselbe Seite gehören:
 *
 * 1. **Version, Geschmacksrichtung, Horchadresse** ({@see Server::describe()}).
 *    Daraus entscheidet das Panel, ob es Datenbanken überhaupt anbietet und ob
 *    das Häkchen für den Fernzugriff sichtbar ist (`docs/36 §12`). Gelesen und
 *    nicht gesetzt: Eine serverweite Horchadresse zu ändern, weil ein Kunde ein
 *    Häkchen gesetzt hat, wäre der Bruch von Leitbild 1.
 * 2. **Befristete Benutzer, die stehengeblieben sind.** Das Zurückspielen eines
 *    Dumps legt einen Benutzer der Form `p1001_r<8 Hexziffern>` an und entfernt
 *    ihn im `finally` (`docs/36 §10.2`). Ein abgebrochener Lauf — Stromausfall,
 *    SIGKILL — kann trotzdem einen zurücklassen, und das wäre ein Zugang ohne
 *    Besitzer.
 *
 * **Gemeldet und nicht gelöscht.** Dieselbe Entscheidung wie bei
 * `SubscriptionRemove::orphansOf()`, das nach dem `userdel` nachsieht, was der
 * UID noch gehört, und die Liste in den Vorgang schreibt. Wer löscht, weiss,
 * was er löscht; ein Aufräumen nebenbei, in einer Operation, die eigentlich nur
 * nachsehen soll, ist die Sorte Nebenwirkung, die niemand erwartet. Entfernt
 * werden sie über `db.user.remove` aus `srvpanel db prune`.
 *
 * **Eine Stunde Karenz.** Ein befristeter Benutzer, den es seit fünf Minuten
 * gibt, gehört sehr wahrscheinlich zu einem Zurückspielen, das gerade läuft.
 * `mysql.global_priv` führt keinen Anlagezeitpunkt; gelesen wird deshalb
 * `password_last_changed` aus dem JSON der Zeile — er wird beim `CREATE USER`
 * gesetzt und danach nicht mehr, weil ein befristeter Benutzer sein Passwort
 * nie wechselt.
 */
final class DbServerInfo implements Op
{
    /** Wie lange ein befristeter Benutzer leben darf, bevor er auffällt. */
    private const GRACE_SECONDS = 3600;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'db.server.info';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $context->progress(40, 'Datenbankserver befragen');

        $info = $this->server->describe($context, $this->session);

        if ($info['available']) {
            $context->progress(75, 'nachsehen, ob etwas zurückblieb');
            $info['stale_users'] = $this->staleUsers($context);
        } else {
            $info['stale_users'] = [];
        }

        $context->progress(100, 'fertig');

        return $info;
    }

    /**
     * Befristete Benutzer, die älter sind als die Karenz.
     *
     * @return list<string>
     */
    private function staleUsers(Context $context): array
    {
        $rows = $this->session->query(
            $context,
            'SELECT user, host, JSON_VALUE(priv, \'$.password_last_changed\') FROM mysql.global_priv',
        );

        $stale = [];
        $now = time();

        foreach ($rows as $row) {
            $user = $row[0] ?? '';

            if (! Names::isEphemeral($user)) {
                continue;
            }

            // Ohne Zeitstempel gilt er als alt. Der umgekehrte Rückfall wäre
            // der bequeme und der falsche: Ein Zugang, über dessen Alter nichts
            // bekannt ist, soll auffallen und nicht verschwinden.
            $changed = isset($row[2]) && is_numeric($row[2]) ? (int) $row[2] : 0;

            if ($now - $changed >= self::GRACE_SECONDS) {
                $stale[] = $user.'@'.($row[1] ?? '');
            }
        }

        sort($stale);

        return $stale;
    }
}
