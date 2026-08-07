<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Op;

/**
 * Einen Datenbankbenutzer entfernen.
 *
 * **Auch diese Datei steht vor ihrer `create`-Hälfte** (`docs/36 §2`). Ein
 * Datenbankbenutzer ist ein Zugang; einer, den niemand mehr kennt und den
 * nichts entfernt, ist ein Zugang ohne Besitzer.
 *
 * **`DROP USER` nimmt die Rechte mit.** Die Zeilen in `mysql.db` und
 * `mysql.tables_priv` verschwinden mit dem Konto — ein `REVOKE` davor wäre
 * überflüssig und, schlimmer, eine zweite Stelle, an der jemand die
 * Rechteliste pflegen müsste.
 *
 * **Wiederholbar** über `IF EXISTS`, aus demselben Grund wie überall in diesem
 * Verzeichnis: Ein zweiter Versuch darf nicht an dem scheitern, was der erste
 * schon geschafft hat.
 */
final class DbUserRemove implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.user.remove';
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
        $prefix = Names::prefix($args['user'] ?? null);
        $account = Names::existing($args['name'] ?? null, 'name');
        $host = Names::host($args['host'] ?? 'localhost');

        // Die Mandantengrenze im Agenten. Sie steht in jeder Operation, die
        // einen bestehenden Namen entgegennimmt, und nicht in einer gemeinsamen
        // Hilfsmethode: Eine Prüfung, die man vergessen kann, wird vergessen —
        // und hier fiele das Vergessen erst auf, wenn ein Kunde den Zugang
        // eines anderen verloren hat.
        if (! Names::belongsTo($account, $prefix)) {
            throw AgentException::denied(sprintf(
                'Der Datenbankbenutzer %s gehört nicht zum Abonnement %s.',
                $account,
                $prefix,
            ));
        }

        $existed = $this->exists($context, $account, $host);

        $context->progress(40, $existed ? 'Benutzer entfernen' : 'Benutzer ist bereits fort');

        $this->session->execute($context, ['DROP USER IF EXISTS '.Sql::account($account, $host)]);

        $context->progress(100, 'fertig');

        return [
            'name' => $account,
            'host' => $host,
            'removed' => $existed,
        ];
    }

    private function exists(Context $context, string $account, string $host): bool
    {
        $rows = $this->session->query($context, sprintf(
            'SELECT user FROM mysql.user WHERE user = %s AND host = %s',
            Sql::text($account),
            Sql::text($host),
        ));

        return $rows !== [];
    }
}
