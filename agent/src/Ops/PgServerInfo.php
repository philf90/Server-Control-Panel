<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\ManagedBlock;
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
            $info['hba_rules'] = $this->hbaRules($context);
        } else {
            $info['stale_roles'] = [];
            $info['hba_rules'] = [];
        }

        $context->progress(100, 'fertig');

        return $info;
    }

    /**
     * Der verwaltete Block aus `pg_hba.conf`, Zeile für Zeile.
     *
     * **Damit `srvpanel db` ihn gegen den Bestand halten kann** (`docs/38
     * §14.4`). Eine Zeile für eine Rolle, die es nicht mehr gibt, ist für
     * PostgreSQL kein Fehler (M22) — sie bleibt liegen, und ohne diese Antwort
     * meldete es niemand. **Gemeldet und nicht gelöscht**, wie bei den
     * befristeten Rollen darunter: Wer löscht, weiss, was er löscht.
     *
     * @return list<string>
     */
    private function hbaRules(Context $context): array
    {
        try {
            $path = $this->server->hbaFile($context, $this->session);

            return ManagedBlock::locked($path, static fn (): array => ManagedBlock::managed(ManagedBlock::read($path)));
        } catch (AgentException) {
            /*
             * **Eine unlesbare `pg_hba.conf` macht diese Auskunft nicht
             * kaputt.** Sie ist die einzige Operation, die auch dann antwortet,
             * wenn wenig steht — das ist ihr Zweck (siehe Klassenkopf). Wer
             * fragt, weil etwas nicht stimmt, soll Fassung, Cluster und Reste
             * bekommen und nicht eine Fehlermeldung über eine Datei, nach der
             * er gar nicht gefragt hat.
             *
             * Der Fall, der das auslöst, ist real und nicht theoretisch: Ein
             * `# BEGIN` ohne `# END` lässt {@see ManagedBlock::managed()} zwar durch,
             * aber ein Betreiber, der die Datei gerade von Hand repariert, hat
             * sie einen Augenblick lang gar nicht.
             */
            return [];
        }
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
