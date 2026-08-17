<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Was der Agent anlegt, kann er auch wieder entfernen.
 *
 * **Dieser Wächter kommt mit P5 und ist nicht datenbankspezifisch — er hätte
 * `docs/35` ein Jahr früher beantwortet.** Die Lücke, die dort auffiel:
 * *Zertifikate liessen sich in diesem System nie löschen.* Der Agent konnte
 * bestellen, hochladen und erneuern; ein `remove` gab es weder im Panel noch in
 * der Registratur. Jedes zurückgebaute Abonnement liess seinen privaten
 * Schlüssel unter `/etc/srvpanel/tls/certs` liegen, und gemerkt hat es niemand,
 * weil ein Grabstein die Zeile am Leben hielt. Aufgefallen ist es erst, als eine
 * Migration danach fragte — und dann gleich zwölfmal.
 *
 * CLAUDE.md fasst es so zusammen: *„Wer etwas anlegt, das auf der Platte
 * bleibt, baut den Weg zurück mit; sonst findet ihn Jahre später eine
 * Datenmigration."* Das war bis hierher ein Satz in einem Dokument. Jetzt ist
 * es eine Prüfung, die zubeisst.
 *
 * P5 legt drei weitere Dinge an, die auf dem System bleiben — Schemata,
 * Datenbankbenutzer und Sicherungsdateien —, und das ist der Anlass. Der
 * Wächter deckt aber die ganze Registratur ab, nicht nur `db.*`.
 */
final class RemovalPathTest extends TestCase
{
    /**
     * Was eine Operation zu einer anlegenden macht.
     *
     * **Nach der Endung und nicht nach einer Liste von Namen.** Eine Liste
     * müsste jemand pflegen, und die Operation, die jemand zu ergänzen
     * vergisst, ist genau die, die dieser Test finden soll. Die Endungen sind
     * die Verben, die dieses Projekt benutzt.
     *
     * `.import` kam mit Schritt 11 dazu: Eine mitgebrachte Sicherung legt eine
     * Datei ab wie eine selbst geschriebene, und ihr Weg zurück ist derselbe —
     * `db.dump.remove`. Sie als Ausnahme zu führen wäre bequemer und falsch:
     * Sie *ist* eine anlegende Operation, sie hiess nur nicht so.
     *
     * @var list<string>
     */
    private const CREATING = ['.create', '.apply', '.provision', '.install', '.store', '.upload', '.ensure', '.issue', '.import'];

    /**
     * Und was eine entfernende ausmacht.
     *
     * Zwei Verben: `remove` für alles, was auf der Platte liegt, `forget` für
     * ein Geheimnis, das der Agent hält (`dns.credential.forget`). Der
     * Unterschied ist keine Laune — „vergessen" ist bei einem Token die
     * ehrlichere Auskunft als „entfernt", weil niemand nachsehen kann.
     *
     * @var list<string>
     */
    private const REMOVING = ['.remove', '.forget'];

    /**
     * Paare, deren Hälften verschieden heissen — mit Grund.
     *
     * @var array<string, string>
     */
    private const PAIRS = [
        // Hochgeladen wird unter `tls.`, entfernt unter `acme.` — beide reden
        // über denselben Ablageort (`Acme\Store`). Der Name ist historisch: Der
        // Ablageort gehörte dem ACME-Client, bevor es das Hochladen gab.
        'tls.certificate.upload' => 'acme.certificate.remove',

        // Ein ACME-Konto wird nicht entfernt, sein Zertifikat schon. Der
        // Kontoschlüssel bleibt im Agenten und gehört keinem Abonnement — er
        // ist die Kennung, unter der dieser Server bei der
        // Zertifizierungsstelle bekannt ist.
        'acme.account.ensure' => 'acme.certificate.remove',
    ];

    /**
     * Anlegende Operationen ohne Gegenstück — jede mit ihrem Grund.
     *
     * Der Grund steht im Wert und nicht in einem Kommentar daneben: Eine Liste
     * ohne Begründung je Eintrag wächst, bis sie alles enthält. Dieselbe Form
     * wie {@see AgentOperationReachTest::WITHOUT_LIFECYCLE}.
     *
     * @var array<string, string>
     */
    private const WITHOUT_REMOVAL = [
        'panel.provision' => 'Richtet das Panel selbst ein. Der Weg zurück ist `apt remove srvpanel` und nicht eine Operation, die sich selbst die Datenbank unter den Füssen wegzieht.',
        'panel.vhost.apply' => 'Der Server-Block der Oberfläche. Ihn zu entfernen hiesse, das Panel vom Netz zu nehmen — das tut das Paket beim Entfernen, nicht der Agent auf Zuruf.',
        'panel.tls.ensure' => 'Das Zertifikat der Oberfläche. Es hat kein Abonnement, an dem es hinge, und ohne es antwortet das Panel nicht mehr. `srvpanel tls prune` fasst es ausdrücklich nicht an (Certificate::forPanel).',
        'pg.server.install' => 'Installiert ein Paket der Distribution. Der Weg zurück ist `apt remove postgresql` und gehört dem Betreiber: Ein `pg.server.remove` im Panel würde mit dem Paket auch jede Kundendatenbank entfernen — und zwar hinter einem Knopf, dessen Beschriftung das nicht sagt. Die Fläche schliesst `srvpanel db --postgres=off`, ohne dass Daten verschwinden.',
        'pg.dump.create' => 'Der Weg zurück ist `db.dump.remove`, und der gilt für beide Systeme: Diese Operation legt eine Datei an, und eine Datei hat kein Datenbanksystem. Ein `pg.dump.remove` wäre Zeile für Zeile dieselbe Operation (docs/38 §13).',
        'pg.dump.import' => 'Übernimmt eine mitgebrachte Datei in dieselbe Ablage — der Weg zurück ist derselbe wie bei pg.dump.create.',
        'sftp.key.apply' => 'Der Weg zurück ist dieselbe Operation mit leerer `keys`-Liste — sie entfernt die Datei, statt sie zu leeren. Ein `sftp.key.remove` wäre eine zweite Fassung desselben Sollzustands, und die zweite ist die, die veraltet: Sie müsste wissen, welche Schlüssel danach übrig sind, und das weiss nur der Bestand. Entfernt wird die Datei ausserdem von `subscription.remove` — sie liegt ausserhalb der Abo-Wurzel, weil der Kunde nicht an sie herankommen soll, und geht deshalb beim Löschen des Verzeichnisses nicht mit.',
        'cron.apply' => 'Der Weg zurück ist dieselbe Operation mit leerer `jobs`-Liste — sie entfernt /etc/cron.d/srvpanel-<benutzer>, statt sie zu leeren: Eine Datei mit nur einem Kopf sähe aus wie „Zeitsteuerung eingerichtet, keine Jobs" und ist dasselbe wie „keine Zeitsteuerung". Ein `cron.remove` wäre eine zweite Fassung desselben Sollzustands. Befehlsdateien, die zu keinem genannten Job mehr gehören, räumt derselbe Aufruf weg; beim Rückbau des Abonnements nimmt `subscription.remove` Datei, Befehle und Ablage mit — alle drei liegen ausserhalb der Abo-Wurzel.',
        'web.logrotate.apply' => 'Die Rotationsdatei eines Abonnements. Sie wird von `subscription.remove` entfernt, und zwar gesucht statt übergeben — eine eigene Operation hätte eine Liste zu führen, die nach einem abgebrochenen Lauf unvollständig ist.',
    ];

    /**
     * Operationen, die eine Datei schreiben, ohne ein anlegendes Verb zu heissen.
     *
     * **Die Lücke, die dieser Eintrag schliesst, hat der Fernzugriff
     * freigelegt** (`docs/36 §22.3t`). Der Test darüber erkennt eine anlegende
     * Operation an ihrem Verb — `create`, `apply`, `provision`. Eine Operation
     * mit einem *Schalter* trägt keines davon: `db.remote.access` schreibt eine
     * Datei nach `/etc`, wenn man sie mit `on` ruft, und nimmt sie mit `off`
     * wieder weg. Für die Verb-Regel ist sie unsichtbar — und damit hätte
     * genau die Datei, deren Wirkung ein offener Datenbankport ist, an der
     * Prüfung vorbeigehen können, die es wegen `docs/35` gibt.
     *
     * Geprüft wird deshalb die Sache statt des Namens: Wer im Agenten
     * `file_put_contents`, `mkdir`, `touch`, `copy` oder `rename` aufruft, legt
     * etwas an, das liegenbleibt. Heisst er nicht danach, sagt er hier, wo der
     * Weg zurück ist.
     *
     * @var array<string, string>
     */
    private const WRITES_WITHOUT_VERB = [
        'db.remote.access' => 'Der Weg zurück ist dieselbe Operation mit `mode: off` — und beim `purge` nimmt packaging/scripts/postremove.sh die Datei mit, weil ein entferntes Panel keinen offenen Datenbankport hinterlassen darf.',
        'web.isolation.probe' => 'Legt ihr Prüfskript im selben Lauf ab und entfernt es im `finally`; über die Operation hinaus bleibt nichts.',
        'pg.remote.access' => 'Zwei Wege zurück, und beide sind dieselbe Operation: `mode: off` nimmt 60-srvpanel.conf mit, eine leere `rules`-Liste nimmt den Block aus pg_hba.conf. Die Datei selbst gehört der Distribution und wird nie entfernt — angefasst wird nur, was zwischen den Marken steht (docs/38 §14).',

        // **P6: die Datei-Operationen des Kunden.** Sie legen ab, was der Kunde
        // ablegen will — und der Weg zurück ist eine eigene Operation für alle
        // vier: `files.remove`. Das ist der Unterschied zu der Lücke aus
        // `docs/35`: Dort blieb ein privater Schlüssel liegen, den *niemand*
        // entfernen konnte, weil es den Griff nicht gab.
        //
        // Und der zweite Weg zurück ist der Rückbau des Abonnements: Was in
        // seiner Wurzel liegt, geht mit `subscription.remove` mit — seit P6
        // über `Filesystem::purgeContents()` in der Sandbox.
        'files.write' => 'Der Weg zurück ist `files.remove`; ausserdem nimmt der Rückbau des Abonnements alles mit.',
        'files.mkdir' => 'Der Weg zurück ist `files.remove` mit `recursive`; ausserdem nimmt der Rückbau des Abonnements alles mit.',
        'files.copy' => 'Der Weg zurück ist `files.remove` auf das Ziel; ausserdem nimmt der Rückbau des Abonnements alles mit.',
        'files.move' => 'Verschiebt und legt nichts Neues an — was am Ziel steht, entfernt `files.remove`.',
        'files.upload' => 'Der Weg zurück ist `files.remove`; das Zwischenlager räumt der Controller im `finally`.',
        'files.extract' => 'Der Weg zurück ist `files.remove` mit `recursive` auf das Zielverzeichnis.',
        'files.compress' => 'Der Weg zurück ist `files.remove` auf das erzeugte Archiv.',

        // **P6 Schritt 8: der SFTP-Zugang.** Beide Wege zurück sind dieselbe
        // Operation mit leerer Liste — und beide sind gebaut, nicht gedacht:
        // `App\Support\Files\Sftp::remove()` ruft sie beim letzten Schlüssel
        // eines Abonnements auf, und der Rückbau des Abonnements nimmt die
        // Schlüsseldatei mit. Das ist der Unterschied zu der Lücke aus docs/35,
        // wo ein privater Schlüssel liegenblieb, den niemand entfernen konnte.
        'sftp.key.apply' => 'Der Weg zurück ist dieselbe Operation mit leerer `keys`-Liste: Sie entfernt /etc/srvpanel/ssh/<benutzer>, statt sie zu leeren — eine leere Datei sähe aus wie „Zugang eingerichtet, keine Schlüssel" und ist dasselbe wie „kein Zugang".',
        'sftp.access' => 'Der Weg zurück ist dieselbe Operation mit leerer `accesses`-Liste; sie nimmt den verwalteten Block aus sshd_config. Die Datei selbst gehört OpenSSH und der Distribution und wird nie entfernt — angefasst wird nur, was zwischen den Marken steht (docs/57 §6).',
    ];

    /** Was im Quelltext einer Operation bedeutet, dass sie etwas auf die Platte legt. */
    private const WRITING_CALLS = '/\b(file_put_contents|mkdir|touch|copy|rename)\s*\(/';

    /**
     * Die Namen aller Operationen des Agenten.
     *
     * @return list<string>
     */
    private function names(): array
    {
        return (new Registry(new Config))->names();
    }

    /**
     * Zu jeder anlegenden Operation gibt es eine entfernende.
     *
     * Gesucht wird die Wurzel: `db.database.create` → `db.database`, und dazu
     * `db.database.remove`. Für `php.version.install` fällt derselbe Weg auf
     * `php.version.remove`.
     */
    public function test_every_creating_operation_has_a_removing_one(): void
    {
        $names = $this->names();
        $creating = [];
        $orphans = [];

        foreach ($names as $name) {
            $stem = $this->stem($name);

            if ($stem === null) {
                continue;
            }

            $creating[] = $name;

            if (array_key_exists($name, self::WITHOUT_REMOVAL)) {
                continue;
            }

            if (isset(self::PAIRS[$name]) && in_array(self::PAIRS[$name], $names, true)) {
                continue;
            }

            $found = false;

            foreach (self::REMOVING as $verb) {
                if (in_array($stem.$verb, $names, true)) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $orphans[] = $name;
            }
        }

        /*
         * **Die Untergrenze zählt mit, und zwar dort, wo die Regel stehen
         * *darf*.** CLAUDE.md nennt die Falle, in die dieses Vorgehen selbst
         * dreimal gelaufen ist: Ein Wächter, der seine Treffer nur an der
         * aktuellen Fundstelle zählt, meldet nach einem Umbau Rot für genau die
         * Ordnung, die er durchsetzen soll. Hier ist die Untergrenze die Zahl
         * der anlegenden Operationen — sinkt sie auf null, weil jemand die
         * Verben umbenannt hat, ist dieser Test leer und grün, und das wäre der
         * schlechtere Ausgang als rot.
         */
        $this->assertGreaterThanOrEqual(
            10,
            count($creating),
            'Es werden kaum anlegende Operationen gefunden — dann prüft dieser Test nichts. '
            .'Sind die Verben in CREATING noch die, die dieses Projekt benutzt?',
        );

        $this->assertSame([], $orphans, sprintf(
            "Diese Operationen legen etwas an, und nichts entfernt es wieder:\n  %s\n\n"
            ."Genau so ist die Zertifikatslücke aus docs/35 entstanden: `create` wurde zuerst gebaut,\n"
            ."funktionierte danach, und `remove` wurde zur Nacharbeit, an die ein Jahr lang niemand dachte.\n"
            .'Entweder fehlt die Gegenoperation — oder sie ist nicht nötig, und dann gehört sie mit Grund '
            .'in RemovalPathTest::WITHOUT_REMOVAL.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * Wer eine Datei schreibt, sagt, wie sie wieder weggeht.
     *
     * **Die Verb-Regel darüber ist eine Abkürzung, und diese Prüfung ist die
     * Sache selbst.** Sie liest den Quelltext jeder Operation: Ruft er
     * `file_put_contents`, `mkdir`, `touch`, `copy` oder `rename`, bleibt etwas
     * liegen. Trägt die Operation dann kein anlegendes Verb im Namen, sieht die
     * Abkürzung sie nicht — und genau dort ist die Lücke, aus der `docs/35`
     * entstanden ist, nur eine Ebene tiefer.
     */
    public function test_every_operation_that_writes_a_file_says_how_it_goes_away(): void
    {
        $ohne = [];
        $gelesen = 0;

        foreach (glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php') ?: [] as $path) {
            $gelesen++;
            $source = (string) file_get_contents($path);

            if (preg_match(self::WRITING_CALLS, $source) !== 1) {
                continue;
            }

            if (preg_match('/public static function name\(\): string\s*\{\s*return \'([a-z0-9.]+)\';/s', $source, $m) !== 1) {
                $ohne[] = basename($path).' — der Name der Operation ist nicht zu lesen';

                continue;
            }

            $name = $m[1];

            if ($this->stem($name) !== null || array_key_exists($name, self::WRITES_WITHOUT_VERB)) {
                continue;
            }

            $ohne[] = $name.' ('.basename($path).')';
        }

        // Die Untergrenze zählt die gelesenen Dateien und nicht die Fundstellen
        // — dieselbe Falle wie eine Zeile weiter oben.
        $this->assertGreaterThan(20, $gelesen, 'Es werden kaum Operationen gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $ohne, sprintf(
            "Diese Operationen legen eine Datei ab und heissen nicht danach:\n  %s\n\n"
            ."Damit sieht sie die Verb-Regel nicht, und eine Datei ohne Weg zurück ist genau die Lücke\n"
            .'aus docs/35. Entweder heisst die Operation nach dem, was sie tut — oder der Weg zurück '
            .'steht mit Grund in RemovalPathTest::WRITES_WITHOUT_VERB.',
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Und die Begründungen zeigen auf etwas Vorhandenes.
     *
     * Dieselbe Gegenrichtung wie in `RouteGuard` und in
     * {@see AgentOperationReachTest}: Eine Ausnahme für eine Operation, die es
     * nicht mehr gibt, fällt sonst nie auf — und deckt irgendwann etwas, an das
     * niemand mehr gedacht hat.
     */
    public function test_every_declared_exception_is_still_an_operation(): void
    {
        $names = $this->names();

        foreach (array_keys(self::WITHOUT_REMOVAL) as $name) {
            $this->assertContains($name, $names, sprintf(
                'WITHOUT_REMOVAL nennt %s; diese Operation gibt es im Agenten nicht mehr.',
                $name,
            ));
        }

        foreach (self::PAIRS as $creating => $removing) {
            $this->assertContains($creating, $names, sprintf('PAIRS nennt %s als anlegend; die Operation gibt es nicht.', $creating));
            $this->assertContains($removing, $names, sprintf('PAIRS nennt %s als entfernend; die Operation gibt es nicht.', $removing));
        }

        foreach (array_keys(self::WRITES_WITHOUT_VERB) as $name) {
            $this->assertContains($name, $names, sprintf(
                'WRITES_WITHOUT_VERB nennt %s; diese Operation gibt es im Agenten nicht mehr.',
                $name,
            ));
        }
    }

    /**
     * Und keine Ausnahme steht da, die keine mehr ist.
     *
     * Wer eine `remove`-Hälfte nachreicht, soll die Begründung loswerden —
     * sonst steht in `WITHOUT_REMOVAL` bald die halbe Registratur mit Gründen,
     * die nicht mehr stimmen.
     */
    public function test_no_exception_is_declared_for_something_that_has_a_removal(): void
    {
        $names = $this->names();
        $stale = [];

        foreach (array_keys(self::WITHOUT_REMOVAL) as $name) {
            $stem = $this->stem($name);

            if ($stem === null) {
                continue;
            }

            foreach (self::REMOVING as $verb) {
                if (in_array($stem.$verb, $names, true)) {
                    $stale[] = sprintf('%s (es gibt %s)', $name, $stem.$verb);

                    break;
                }
            }
        }

        $this->assertSame([], $stale, sprintf(
            "Für diese Operationen gibt es inzwischen ein Gegenstück, und die Ausnahme steht noch da:\n  %s",
            implode("\n  ", $stale),
        ));
    }

    /** Die Wurzel einer anlegenden Operation, oder `null`. */
    private function stem(string $name): ?string
    {
        foreach (self::CREATING as $verb) {
            if (str_ends_with($name, $verb)) {
                return substr($name, 0, -strlen($verb));
            }
        }

        return null;
    }
}
