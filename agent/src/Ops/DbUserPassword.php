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
 * Das Passwort eines Datenbankbenutzers neu setzen.
 *
 * **Der Weg zurück zu einem verlorenen Passwort — und der einzige.** Weil das
 * Passwort nirgends abgelegt wird (`docs/36 §4`, Entscheidung 3), gibt es kein
 * Nachschlagen; wer seines nicht mehr hat, setzt ein neues und trägt es in
 * seine Anwendung ein. Das ist eine Handlung mehr als bei Plesk und der Preis
 * dafür, dass die Antwort auf „wo liegen die Datenbankpasswörter meiner
 * Kunden" *nirgends* lautet.
 *
 * **Läuft nie über die Warteschlange**, aus demselben Grund wie
 * {@see DbUserCreate}: `operations.payload` liegt dauerhaft in der Datenbank
 * des Panels.
 *
 * **`ALTER USER` und nicht `SET PASSWORD`.** Beides ginge; `ALTER USER` kennt
 * `IF EXISTS` und ist damit die Anweisung, deren Fehlschlag eine Aussage über
 * den Benutzer ist und nicht über die Syntax. Ohne `IF EXISTS` allerdings —
 * hier ist ein fehlender Benutzer **kein** gewünschter Zustand, sondern ein
 * Widerspruch zum Bestand des Panels, und der gehört gemeldet.
 */
final class DbUserPassword implements Op
{
    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'db.user.password';
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
        $password = Guard::string($args['password'] ?? null, 'password');

        if (! Names::belongsTo($account, $prefix)) {
            throw AgentException::denied(sprintf(
                'Der Datenbankbenutzer %s gehört nicht zum Abonnement %s.',
                $account,
                $prefix,
            ));
        }

        if (! preg_match('/^[A-Za-z0-9]{16,128}$/D', $password)) {
            throw AgentException::badRequest(
                'Das Passwort wird vom Panel erzeugt — erwartet werden 16 bis 128 Buchstaben und Ziffern.',
            );
        }

        $context->progress(50, 'Passwort setzen');

        $this->session->execute($context, [sprintf(
            'ALTER USER %s IDENTIFIED BY %s',
            Sql::account($account, $host),
            Sql::text($password),
        )]);

        $context->progress(100, 'fertig');

        return ['name' => $account, 'host' => $host];
    }
}
