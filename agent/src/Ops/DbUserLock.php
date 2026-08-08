<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Db\Session;
use SrvPanel\Agent\Db\Sql;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Die Datenbankzugänge eines Abonnements sperren und wieder freigeben.
 *
 * **Die Sperre eines Abonnements erreicht damit erstmals seine Datenbank**
 * (`docs/36 §6`). Bis P4 nahm `subscription.suspend` dem Abo-Verzeichnis das
 * Ausführungsbit, und `WebLifecycle` schrieb jeden Server-Block auf 503 um. Die
 * Datenbank blieb davon unberührt — ein gesperrtes Abonnement wäre eines
 * gewesen, dessen Webseite abgeschaltet ist und dessen Datenbank jede Anwendung
 * weiterbedient, die die Zugangsdaten hat. Auf demselben Server über den
 * Socket, und bei freigeschaltetem Fernzugriff von überall. Das ist keine
 * Sperre, sondern eine abgeschaltete Webseite.
 *
 * **`ACCOUNT LOCK` und nicht `REVOKE`.** Das Zurücknehmen der Rechte wäre die
 * naheliegende Alternative und die schlechtere: Es müsste sich merken, was es
 * weggenommen hat, um es zurückgeben zu können — also einen zweiten Zustand
 * neben `status` führen, und der zweite Zustand ist der, der veraltet.
 * `ACCOUNT LOCK` nimmt die Anmeldung und lässt Schema, Tabellen und Rechte
 * unberührt; `UNLOCK` ist die vollständige Umkehrung.
 *
 * Die Anweisung gibt es in MariaDB ab 10.4.2 und in MySQL ab 5.7.6; alle vier
 * Zielplattformen liegen darüber. {@see Server} prüft es beim Anlegen, damit
 * auf einem Server ohne die Anweisung gar keine Datenbank entsteht — statt
 * einer, die sich nicht sperren lässt.
 *
 * **Alle Benutzer des Abonnements in einem Aufruf.** Die Namen kommen aus dem
 * Bestand des Panels; ein Aufruf je Benutzer wären bei einem Kunden mit fünf
 * Zugängen fünf Vorgänge für eine Handlung, und „teilweise gesperrt" ist keine
 * Auskunft.
 */
final class DbUserLock implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.user.lock';
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
        $locked = Guard::enum($args['mode'] ?? null, ['lock', 'unlock'], 'mode') === 'lock';

        $accounts = $this->accounts($args['users'] ?? [], $prefix);

        $context->progress(40, $locked ? 'Zugänge sperren' : 'Zugänge freigeben');

        $statements = [];

        foreach ($accounts as [$account, $host]) {
            /*
             * **`IF EXISTS`, weil der Bestand des Panels vorausgehen darf.**
             * Ein Benutzer, den jemand von Hand in `mysql` entfernt hat, würde
             * den ganzen Aufruf zum Scheitern bringen — und damit bliebe eine
             * Sperre aus, weil ein *anderer* Zugang fehlt. Die Sperre ist
             * wichtiger als die Vollständigkeit der Buchführung.
             */
            $statements[] = sprintf(
                'ALTER USER IF EXISTS %s ACCOUNT %s',
                Sql::account($account, $host),
                $locked ? 'LOCK' : 'UNLOCK',
            );
        }

        $this->session->execute($context, $statements);

        $context->progress(100, 'fertig');

        return [
            'locked' => $locked,
            'users' => array_map(
                static fn (array $account): string => $account[0].'@'.$account[1],
                $accounts,
            ),
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function accounts(mixed $value, string $prefix): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('users muss eine Liste sein.');
        }

        $accounts = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jeder Eintrag in users ist eine Ablage aus name und host.');
            }

            $account = Names::existing($entry['name'] ?? null, 'users.name');

            if (! Names::belongsTo($account, $prefix)) {
                throw AgentException::denied(sprintf(
                    'Der Datenbankbenutzer %s gehört nicht zum Abonnement %s.',
                    $account,
                    $prefix,
                ));
            }

            $accounts[] = [$account, Names::host($entry['host'] ?? 'localhost')];
        }

        return $accounts;
    }
}
