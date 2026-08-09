<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;

/**
 * Was für ein PostgreSQL hier läuft — und was von uns noch herumliegt.
 *
 * Das Gegenstück zu {@see DbServerInfo}, und wie dort zwei Auskünfte in einem
 * Aufruf, weil beide dieselbe Verbindung brauchen.
 *
 * **Sie läuft auch dann, wenn nichts da ist.** Das ist der Unterschied zu jeder
 * anderen `pg.*`-Operation: Sie ist die einzige, die eine Antwort hat, wenn
 * PostgreSQL fehlt, wenn der Dienst steht oder wenn die Rolle `root` noch nicht
 * angelegt ist. Genau dafür gibt es sie — das Panel entscheidet daraus, ob es
 * PostgreSQL anbietet, und zeigt sonst den Befehl aus
 * {@see Server::HANDOVER} an (`docs/38 §6.1`).
 *
 * **Gemeldet und nicht gelöscht**, wortgleich wie in {@see DbServerInfo}: Die
 * befristeten Rollen eines abgebrochenen Zurückspielens stehen in der Antwort,
 * entfernt werden sie über `pg.role.remove` aus `srvpanel db prune`. Wer löscht,
 * weiss, was er löscht.
 */
final class PgServerInfo implements Op
{
    /**
     * Wie lange eine befristete Rolle leben darf, bevor sie auffällt.
     *
     * Dieselbe Stunde wie in P5 — und hier ist der Zeitstempel leichter zu
     * bekommen: `pg_roles` führt keinen Anlagezeitpunkt, aber
     * `pg_stat_activity` verrät nichts über eine Rolle, die gerade nicht
     * arbeitet. Gelesen wird deshalb, ob überhaupt eine Sitzung unter ihr
     * läuft; tut das keine, ist sie ein Rest.
     */
    private const GRACE_SECONDS = 3600;

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
    ) {}

    public static function name(): string
    {
        return 'pg.server.info';
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
        $context->progress(40, 'PostgreSQL befragen');

        $info = $this->server->describe($context, $this->session);

        if ($info['handed_over']) {
            $context->progress(75, 'nachsehen, ob etwas zurückblieb');
            $info['stale_roles'] = $this->staleRoles($context);
        } else {
            $info['stale_roles'] = [];
        }

        $context->progress(100, 'fertig');

        return $info;
    }

    /**
     * Befristete Rollen, unter denen gerade nichts läuft.
     *
     * **Die Frage ist eine andere als in P5, und der Grund ist ein fehlender
     * Zeitstempel.** `mysql.global_priv` führt `password_last_changed`;
     * `pg_roles` führt nichts dergleichen. Gefragt wird deshalb nach dem
     * *Betrieb*: Eine befristete Rolle gehört zu einem Vorgang, der läuft — und
     * wenn keine Sitzung unter ihr offen ist und ihre älteste Sitzung länger als
     * die Karenz her ist, gehört sie zu keinem mehr.
     *
     * `backend_start` liefert den Anfang der Sitzung; gibt es keine, ist die
     * Rolle ein Rest. Der Rückfall ist damit „sie ist alt" — dieselbe Richtung
     * wie in P5: **Ein Zugang, über dessen Alter nichts bekannt ist, soll
     * auffallen und nicht verschwinden.**
     *
     * @return list<string>
     */
    private function staleRoles(Context $context): array
    {
        $rows = $this->session->query($context, sprintf(
            'SELECT r.rolname, COALESCE(MAX(EXTRACT(EPOCH FROM (now() - a.backend_start)))::bigint, %d) '
            .'FROM pg_roles r LEFT JOIN pg_stat_activity a ON a.usename = r.rolname '
            .'GROUP BY r.rolname',
            self::GRACE_SECONDS,
        ));

        $stale = [];

        foreach ($rows as $row) {
            $role = $row[0] ?? '';

            if (! Names::isEphemeral($role)) {
                continue;
            }

            if ((int) ($row[1] ?? self::GRACE_SECONDS) >= self::GRACE_SECONDS) {
                $stale[] = $role;
            }
        }

        sort($stale);

        return $stale;
    }
}
