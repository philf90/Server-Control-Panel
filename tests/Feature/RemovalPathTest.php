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
     * @var list<string>
     */
    private const CREATING = ['.create', '.apply', '.provision', '.install', '.store', '.upload', '.ensure', '.issue'];

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
        'web.logrotate.apply' => 'Die Rotationsdatei eines Abonnements. Sie wird von `subscription.remove` entfernt, und zwar gesucht statt übergeben — eine eigene Operation hätte eine Liste zu führen, die nach einem abgebrochenen Lauf unvollständig ist.',
    ];

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
