<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Credentials;
use SrvPanel\Agent\Pg\Ephemeral;
use SrvPanel\Agent\Pg\Hba;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Owner;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;

/**
 * Eine Sicherung zurückspielen — unter einer Rolle, die fast nichts darf.
 *
 * ## `ON_ERROR_STOP=1` ist kein Schalter, sondern die halbe Operation
 *
 * `psql -f` gibt bei gescheitertem SQL **0** zurück und arbeitet weiter.
 * Gemessen am 9. August 2026 an vier Anweisungen, deren dritte abgewiesen wurde:
 * Rückgabewert 0, und die vierte lief trotzdem. Für ein Zurückspielen heisst
 * das: eine halb eingespielte Datenbank, gemeldet als Erfolg.
 *
 * Mit dem Schalter: Rückgabewert **3**, Abbruch an der scheiternden Zeile, und
 * eine Meldung, die Datei, Zeilennummer und Grund nennt —
 *
 *     psql:/…/x7f3a…-2026.sql:3: ERROR:  relation "gibt_es_nicht" does not exist
 *
 * Das ist zugleich der Beleg, den `docs/38 §3` für die Eindämmung verlangt:
 * Steht in einem mitgebrachten Dump ein `CREATE DATABASE` oder ein
 * `ALTER ROLE … SUPERUSER`, ist die Meldung des Systems die Antwort, wörtlich
 * und mit der Zeile, in der es stand.
 *
 * **`mysql` macht es von selbst richtig** — es bricht bei einem Fehler ab,
 * solange niemand `--force` sagt. Das ist die Stelle, an der P5b sich am
 * leichtesten hätte täuschen lassen: Wer aus P5 abschreibt, schreibt eine
 * Vorsicht ab, die dort in der Abwesenheit eines Schalters lag, und hier in
 * seiner Anwesenheit liegen muss.
 *
 * ## Zwei Voraussetzungen, die vor dem ersten Lauf entstehen
 *
 * Die befristete Rolle meldet sich über den Unix-Socket mit Passwort an, und
 * Debians `pg_hba.conf` lässt das nicht zu (`docs/38 §13.4`, Befund vom
 * 9. August). Deshalb sorgt diese Operation vor dem Lauf für beides: die
 * Gruppenrolle ({@see Ephemeral::group()}) und die Zeile in `pg_hba.conf`
 * ({@see Hba::ensure()}). **Beides ist ohne Wirkung, wenn es schon da ist** —
 * geschrieben und neu geladen wird nur beim ersten Mal.
 *
 * Sie stehen hier und nicht in `pg.server.install`, und der Grund ist ein
 * Muster aus P3: Eine Voraussetzung, die nur beim Einrichten hergestellt wird,
 * fehlt auf jedem Server, der vor dieser Fassung eingerichtet wurde. Was eine
 * Operation braucht, stellt sie selbst sicher.
 *
 * ## Und eine dritte, die dem Kunden seine Daten erhält
 *
 * **Ein Zurückspielen, das gelingt, kann trotzdem falsch ausgehen.** In P5b hat
 * es zweimal nicht getan, was es sollte, und beide Male hat es Erfolg gemeldet
 * oder wäre nie losgelaufen (`docs/39`, Punkt 7):
 *
 * - In eine Datenbank mit Tabellen kam es gar nicht hinein —
 *   `ERROR: relation "kunden" already exists`, weil `pg_dump --format=plain`
 *   kein `DROP` schreibt und `mysqldump` seines von selbst mitbringt.
 * - Und was hineinkam, gehörte danach `root`. Der Kunde stand vor seinen eigenen
 *   Zeilen.
 *
 * Beides beantwortet {@see Owner}: {@see self::retrofit()} zieht die
 * Eigentümerrolle nach — auch für Datenbanken, die es vor ihr gab —,
 * {@see Owner::reset()} leert das Schema **privilegiert**, und die befristete
 * Rolle arbeitet als sie. Was eingespielt wird, gehört danach dem Abonnement.
 */
final class PgRestore implements Op
{
    public function __construct(
        private readonly Session $session = new Session,
        private readonly Ephemeral $ephemeral = new Ephemeral,
        private readonly Server $server = new Server,
        private readonly Owner $owner = new Owner,
    ) {}

    public static function name(): string
    {
        return 'pg.restore';
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
        $prefix = Names::prefix($args['prefix'] ?? null);
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

        $context->progress(10, 'Zugang vorbereiten');
        $this->prepare($context);

        $context->progress(20, 'Eigentum ordnen');
        $owner = $this->retrofit($context, $prefix, $database);

        $plain = Dump::directory($subscription).'/.'.$storage.'.restore.sql';

        try {
            $context->progress(25, 'Sicherung auspacken');
            Dump::decompress($source, $plain);

            /*
             * **Geleert wird privilegiert, eingespielt nicht.** Das ist die
             * ganze Begründung für die Reihenfolge hier und steht ausführlich in
             * {@see Owner::reset()}: `pg_dump --format=plain` schreibt kein
             * `DROP`, und `--clean` liefe unter der befristeten Rolle, die nicht
             * wegräumen darf, was ihr nicht gehört.
             *
             * **Nach dem Auspacken und nicht davor.** Ist der Dump beschädigt
             * oder die Platte voll, hat der Kunde nach einem Abbruch noch seine
             * Daten. Die Reihenfolge kostet nichts und ist der Unterschied
             * zwischen „das Zurückspielen ging schief" und „die Datenbank ist
             * leer".
             */
            $context->progress(40, 'Datenbank leeren');
            $this->session->execute($context, Owner::reset($owner), $database);

            $context->progress(45, 'zurückspielen');

            $this->ephemeral->with(
                $context,
                $prefix,
                $database,
                function (Credentials $as) use ($context, $database, $plain): bool {
                    $this->session->restore($context, $as, $database, $plain);

                    return true;
                },
            );
        } finally {
            // Die ausgepackte Fassung geht in jedem Fall. Sie ist die grössere
            // von beiden und enthält dieselben Daten wie die, die bleibt.
            @unlink($plain);
        }

        $context->progress(100, 'fertig');

        return ['name' => $database, 'storage' => $storage, 'owner' => $owner];
    }

    /**
     * Die Eigentümerrolle des Abonnements — angelegt, besetzt, angenommen.
     *
     * **Das ist die Nachrüstung für alles, was vor ihr entstanden ist**, und sie
     * steht hier, weil der Betreiber sie hierhin gelegt hat: Das Zurückspielen
     * setzt das Schema ohnehin neu auf, und die Rolle dort einzuziehen kostet
     * keine eigene Wanderung. Eine Datenbank aus P5b vor dieser Fassung hat ein
     * Schema, das `root` gehört, und Zugänge, die von der Rolle nichts wissen.
     *
     * Drei Schritte, und jeder ist wiederholbar:
     *
     * 1. Die Rolle anlegen, falls es sie nicht gibt.
     * 2. Jeden Zugang des Abonnements zum Mitglied machen — welche das sind,
     *    fragt {@see Owner::roles()} den Katalog. Der Grund für die Ausnahme von
     *    der Regel „die Anwendung sagt es" steht dort.
     * 3. Und ihn in **dieser** Datenbank als sie arbeiten lassen.
     *
     * Das Schema selbst bekommt seinen Eigentümer erst beim Leeren
     * ({@see Owner::reset()}) — vorher wäre es eine Anweisung, die das nächste
     * `DROP SCHEMA` gleich wieder wegwirft.
     *
     * `GRANT` auf eine bestehende Mitgliedschaft und `SET role` auf einen
     * bestehenden Eintrag sind beide folgenlos; gemessen am 10. August 2026.
     */
    private function retrofit(Context $context, string $prefix, string $database): string
    {
        $owner = $this->owner->ensure($context, $prefix);

        foreach ($this->owner->roles($context, $prefix, $database) as $role) {
            $this->session->execute($context, [
                Owner::membership($owner, $role),
                Owner::sessionRole($owner, $role, $database, true),
            ]);
        }

        return $owner;
    }

    /**
     * Gruppenrolle und `pg_hba.conf` — beide nur, wenn sie fehlen.
     *
     * **Neu geladen wird nur nach einer Änderung.** `pg_ctlcluster … reload`
     * bei jedem Zurückspielen wäre ein Signal an den Server für nichts — und
     * ein Signal, das ein anderer Vorgang gerade nicht gebrauchen kann.
     */
    private function prepare(Context $context): void
    {
        $this->ephemeral->group($context);

        $cluster = $this->server->primaryCluster($context);

        if ($cluster === null) {
            throw AgentException::execFailed('Es gibt keinen laufenden PostgreSQL-Cluster.');
        }

        if (! Hba::ensure($this->server->hbaFile($context, $this->session))) {
            return;
        }

        $context->journal->write('pg_hba.conf um die Zeile für das Zurückspielen ergänzt', [
            'cluster' => $cluster['version'].'/'.$cluster['name'],
        ]);

        $result = $context->runner->run('pg_ctlcluster', [
            (string) $cluster['version'],
            (string) $cluster['name'],
            'reload',
        ], 60);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Die Anmeldedaten liessen sich nicht neu laden: '.$result->message(),
            );
        }
    }
}
