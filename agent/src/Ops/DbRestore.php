<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Credentials;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Db\Ephemeral;
use SrvPanel\Agent\Db\Names;
use SrvPanel\Agent\Op;

/**
 * Eine Sicherung zurückspielen — unter einem befristeten Benutzer.
 *
 * **Das ist die sicherheitsrelevanteste Operation von P5.** Ein Dump ist
 * beliebiges SQL, und der Kunde lädt ihn hoch. Als Datenbank-`root` über den
 * Socket eingespielt, wäre
 *
 *     GRANT ALL PRIVILEGES ON *.* TO 'p1001_web'@'localhost';
 *
 * in einer Zeile des Dumps genau der Ausbruch, den das Abnahmekriterium
 * ausschliesst — und er stünde nicht einmal in einem Angriff, sondern in einem
 * Dump, den jemand von einem anderen Server mitgebracht hat.
 *
 * {@see Ephemeral} legt deshalb einen Benutzer mit Rechten auf **genau die eine
 * Zieldatenbank** an, spielt darunter ein und räumt ihn im `finally` wieder ab.
 * Ein `CREATE DATABASE` oder `USE andere_datenbank` im Dump scheitert damit an
 * den Rechten, laut und mit der Meldung des Systems — und ist kein Sonderfall,
 * den jemand abfangen muss.
 *
 * **Die Eingabe geht als Datei und nicht als Zeichenkette.** `Runner::run()`
 * hat dafür seit P5 den Parameter `$inputFile`: `$input` läge vollständig im
 * Speicher, und das wäre bei einer Sicherung von zwei Gigabyte der Weg, auf dem
 * der Agent den Arbeitsspeicher des Servers füllt. `mysql` liest kein gzip,
 * also wird vorher auf die Platte ausgepackt und danach aufgeräumt.
 */
final class DbRestore implements Op
{
    /** Wie lange ein Einspielen laufen darf. Wie beim Sichern: vier Stunden. */
    private const TIMEOUT = 14_400;

    public function __construct(private readonly Ephemeral $ephemeral = new Ephemeral) {}

    public static function name(): string
    {
        return 'db.restore';
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
        $database = Names::existing($args['name'] ?? null, 'name');
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $storage = Dump::storageName(is_string($args['storage'] ?? null) ? $args['storage'] : '');

        if (! Names::belongsTo($database, $prefix)) {
            throw AgentException::denied(sprintf(
                'Die Datenbank %s gehört nicht zum Abonnement %s.',
                $database,
                $prefix,
            ));
        }

        $source = Dump::path($subscription, $storage);

        if (! is_file($source)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Diese Sicherung gibt es nicht mehr.',
                ['storage' => $storage],
            );
        }

        $plain = Dump::directory($subscription).'/.'.$storage.'.restore.sql';

        try {
            $context->progress(20, 'Sicherung auspacken');
            Dump::decompress($source, $plain);

            $context->progress(45, 'einspielen');

            $this->ephemeral->with(
                $context,
                $prefix,
                $database,
                fn (Credentials $as): bool => $this->feed($context, $as, $database, $plain),
            );
        } finally {
            // Die ausgepackte Fassung geht in jedem Fall. Sie ist die grössere
            // von beiden und enthält dieselben Daten wie die, die bleibt.
            @unlink($plain);
        }

        $context->progress(100, 'fertig');

        return ['name' => $database, 'storage' => $storage];
    }

    /**
     * Der Lauf von `mysql` unter dem befristeten Benutzer.
     *
     * **Kein `--force`.** Es liesse `mysql` über Fehler hinweglaufen und am
     * Ende erfolgreich enden — bei einem Zurückspielen wäre das eine halb
     * eingespielte Datenbank, gemeldet als Erfolg. Genau die Sorte Ausgang, die
     * dieses Projekt sonst überall vermeidet.
     */
    private function feed(Context $context, Credentials $as, string $database, string $plain): bool
    {
        $file = null;

        try {
            $file = $as->write();

            $result = $context->runner->run(
                'mysql',
                ['--defaults-extra-file='.$file, '--protocol=socket', $database],
                self::TIMEOUT,
                null,
                null,
                fn (): bool => $context->abandoned(),
                $plain,
            );
        } finally {
            if ($file !== null) {
                @unlink($file);
            }
        }

        if (! $result->successful()) {
            /*
             * **Die Meldung des Systems, nicht eine Umschreibung davon**
             * (Plan §2, Leitbild 2). Sie enthält die Zeilennummer, an der es
             * abgebrochen ist — und bei einem Dump, der Rechte vergeben will,
             * das „Access denied", das die Eindämmung belegt.
             */
            throw AgentException::execFailed('Das Zurückspielen ist gescheitert: '.$result->message());
        }

        return true;
    }
}
