<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Names;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\Pg\Sql;

/**
 * Eine Sicherung einer PostgreSQL-Datenbank.
 *
 * **Die Ablage ist dieselbe wie in P5, und das ist eine Entscheidung**
 * (`docs/38 §13`): `/var/lib/srvpanel/dumps/<abonnement>/<name>.sql.gz`,
 * `root:srvpanel`, Verzeichnisse `0710`, Dateien `0640`. Ein Dump ist ein Dump
 * — die Frage, wer ihn herunterladen darf, hängt nicht daran, welches
 * Datenbanksystem ihn geschrieben hat. Deshalb steht hier {@see Dump} aus dem
 * `Db`-Namensraum und keine zweite Klasse daneben.
 *
 * **Und deshalb gibt es auch kein `pg.dump.remove`.** `db.dump.remove` entfernt
 * eine Datei, und eine Datei hat kein Datenbanksystem; eine zweite Operation
 * wäre Zeile für Zeile dieselbe. Sie steht mit dieser Begründung in
 * `RemovalPathTest::WITHOUT_REMOVAL` — der Weg zurück fehlt nicht, er hat nur
 * einen anderen Namen.
 *
 * ## Zwei Unterschiede zu `db.dump.create`, beide gemessen
 *
 * **1. Kein DEFINER-Filter.** `pg_dump` schreibt keine `DEFINER`-Angaben
 * (gemessen am 9. August 2026: null Treffer in einem Dump mit Tabelle,
 * Eigentümer und Rechtevergabe). Der Filter aus `docs/36 §10.1` entfällt
 * ersatzlos — und weil ein Filter über Kundendaten läuft, ist „entfällt" hier
 * mehr wert als „schadet nicht": Er käme an jede Zeile, die ein Kunde
 * gespeichert hat.
 *
 * **2. `--no-owner --no-privileges`.** Ohne sie schreibt `pg_dump` Zeilen wie
 * `ALTER TABLE … OWNER TO x7f3a…_web` und `GRANT … TO …` in den Dump. Beim
 * Zurückspielen auf demselben Server ginge das gut; beim Umzug auf einen
 * anderen — der Normalfall für eine Sicherung — zeigen sie auf Rollen, die es
 * dort nicht gibt. Gemessen: mit den Schaltern null `OWNER TO`-Zeilen, ohne sie
 * eine. Die Rechte stellt das Panel her, nicht der Dump.
 *
 * **`--format=plain` steht ausdrücklich da**, obwohl es die Vorgabe ist: Der
 * Rest dieses Weges — auspacken, `psql -f`, die Fehlermeldung mit Zeilennummer
 * — hängt daran, dass die Datei Text ist. Ein `--format=custom` liesse sich nur
 * mit `pg_restore` einspielen, und dann stünde die halbe Begründung von
 * {@see PgRestore} auf einer Vorgabe, die niemand hier getroffen hat.
 */
final class PgDumpCreate implements Op
{
    /**
     * Wie viel freier Platz mindestens übrig bleiben muss.
     *
     * Wortgleich aus {@see DbDumpCreate}: Ein Dateisystem, das auf das letzte
     * Byte vollläuft, nimmt alles mit, was gerade schreibt — auch die
     * Protokolle, in denen der Grund stünde.
     */
    private const RESERVE_BYTES = 512 * 1024 * 1024;

    /**
     * Der Sicherheitsaufschlag auf die geschätzte Grösse.
     *
     * `pg_database_size()` ist der Platz **auf der Platte**, einschliesslich
     * Indizes und totem Raum, den ein `VACUUM` noch nicht geholt hat. Die
     * Textfassung ist regelmässig kleiner — Indizes stehen als eine Zeile
     * `CREATE INDEX` darin und nicht als Baum. Der Faktor bleibt trotzdem drei
     * und damit grob in die richtige Richtung: Roh und komprimiert liegen kurz
     * nebeneinander, und eine Tabelle mit langen Texten kann als `COPY`-Block
     * auch grösser werden.
     */
    private const HEADROOM = 3;

    /** Wie lange ein `pg_dump` laufen darf. Vier Stunden, wie in P5. */
    private const TIMEOUT = 14_400;

    public function __construct(private readonly Session $session = new Session) {}

    public static function name(): string
    {
        return 'pg.dump.create';
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

        $context->progress(10, 'Platz prüfen');
        $this->requireSpace($context, $database);

        $directory = Dump::prepare($subscription);
        $target = Dump::path($subscription, $storage);
        $raw = $directory.'/.'.$storage.'.sql';

        try {
            $context->progress(25, 'Datenbank auslesen');
            $this->run($context, $database, $raw);

            $context->progress(70, 'komprimieren');

            // Ohne Filter — siehe den Kopf dieser Klasse.
            $bytes = Dump::compress($raw, $target, fn (): bool => $context->abandoned());

            $this->handOver($target);
        } finally {
            // Die Rohfassung geht in jedem Fall — auch nach einem Abbruch.
            @unlink($raw);
        }

        $context->progress(100, 'fertig');

        return ['name' => $database, 'storage' => $storage, 'bytes' => $bytes];
    }

    /**
     * Der Lauf von `pg_dump`.
     *
     * **`--file=` und nicht das Rohr.** Derselbe Grund wie in P5 und dort teuer
     * gelernt (`docs/36 §10.1`): `Runner` deckelt die gesammelte Ausgabe bei
     * 4 MiB. Ein Dump durch dieses Rohr käme abgeschnitten an — und eine
     * abgeschnittene Sicherung ist schlimmer als keine, weil sie aussieht wie
     * eine.
     */
    private function run(Context $context, string $database, string $raw): void
    {
        $result = $context->runner->run('pg_dump', [
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            '--file='.$raw,
            '--host=/var/run/postgresql',
            '--username=root',
            '--dbname='.$database,
        ], self::TIMEOUT, null, null, fn (): bool => $context->abandoned());

        if (! $result->successful()) {
            throw AgentException::execFailed('Die Sicherung ist gescheitert: '.$result->message());
        }
    }

    /**
     * Die fertige Datei an das Panel übergeben — `root:srvpanel 0640`.
     *
     * Wortgleich {@see DbDumpCreate::handOver()}, samt der Vorsicht gegenüber
     * einer Gruppe, die es nicht gibt: Die Datei gehört dann root allein — enger
     * als vorgesehen, nicht weiter.
     */
    private function handOver(string $path): void
    {
        if (posix_getgrnam(Dump::GROUP) !== false) {
            chgrp($path, Dump::GROUP);
        }

        chown($path, 'root');
        chmod($path, Dump::FILE_MODE);
    }

    /**
     * Genug Platz für Rohfassung und komprimierte Fassung?
     *
     * **Vorher fragen und nicht hinterher aufräumen.** Ein `pg_dump`, der nach
     * zwanzig Minuten am vollen Dateisystem abbricht, hat zwanzig Minuten
     * gekostet und eine halbe Datei hinterlassen.
     */
    private function requireSpace(Context $context, string $database): void
    {
        $rows = $this->session->query($context, sprintf(
            'SELECT pg_database_size(%s)',
            Sql::text($database),
        ));

        $size = (int) ($rows[0][0] ?? 0);
        $needed = $size * self::HEADROOM + self::RESERVE_BYTES;
        $free = @disk_free_space(Dump::ROOT) ?: @disk_free_space('/var/lib');

        if ($free === false || $free >= $needed) {
            return;
        }

        throw AgentException::execFailed(sprintf(
            'Zu wenig Platz für die Sicherung: gebraucht werden etwa %d MiB, frei sind %d MiB.',
            intdiv($needed, 1024 * 1024),
            intdiv((int) $free, 1024 * 1024),
        ));
    }
}
