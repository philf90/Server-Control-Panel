<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Das Gerüst des Abonnements gehört dem Panel, sein Inhalt dem Kunden.
 *
 * ## Der Anlass
 *
 * Gefunden bei der Frage des Betreibers nach einer Mehrfachauswahl: Was heute
 * passiert, wenn jemand `httpdocs` auswählt und „Entfernen" drückt, ist
 * schlimmer als eine Abweisung.
 *
 * 1. `Filesystem::removeTree()` räumt das Verzeichnis leer — der Inhalt gehört
 *    dem Kunden, jedes `unlink` gelingt.
 * 2. Das `rmdir` scheitert, weil die Vhost-Wurzel `root:root` gehört.
 * 3. Gemeldet wird „Das Verzeichnis liess sich nicht vollständig entfernen."
 *
 * **Die Webseite ist weg, und die Meldung sagt, es sei nichts passiert.**
 *
 * > **Ein Vorgang, der scheitert, nachdem er die Hälfte getan hat, meldet einen
 * > Fehlschlag und hinterlässt eine Wirkung.**
 *
 * ## Warum die Liste nicht abgetippt wird
 *
 * Sie kommt aus {@see SubscriptionProvision::reservedDirectories()} und wächst
 * damit mit dem Schema. Eine zweite Aufzählung — hier, im Panel oder in der
 * Oberfläche — wäre die Fassung, die beim nächsten Zuwachs veraltet.
 */
final class SchemeProtectionTest extends TestCase
{
    /**
     * Jedes Verzeichnis des Schemas ist geschützt, und die Liste wird nicht
     * abgetippt.
     *
     * Geprüft wird gegen die **Quelle** der Liste und nicht gegen eine
     * Aufzählung in diesem Test: Käme morgen `cache` dazu, wäre eine
     * abgetippte Liste hier grün und der Schutz unvollständig.
     */
    public function test_every_directory_of_the_scheme_is_protected(): void
    {
        $erwartet = [
            SubscriptionProvision::DOCUMENT_ROOT,
            ...SubscriptionProvision::reservedDirectories(),
        ];

        $this->assertGreaterThan(
            4,
            count($erwartet),
            'Es werden fast keine Verzeichnisse des Schemas gefunden — dann liest dieser '.
            'Wächter die Liste nicht mehr, und seine Zusage ist wertlos.',
        );

        foreach ($erwartet as $name) {
            $this->assertTrue(
                Scheme::isFixed('/'.$name),
                sprintf('`/%s` gehört zum Schema und ist nicht geschützt.', $name),
            );
        }
    }

    /**
     * Der Inhalt gehört dem Kunden.
     *
     * **Das ist die andere Hälfte der Regel**, und ohne sie wäre der Schutz
     * schlimmer als keiner: `httpdocs` leerzuräumen ist genau das, was jemand
     * vor einem neuen Deploy tut.
     */
    public function test_what_lies_inside_is_not(): void
    {
        foreach ([
            '/httpdocs/index.html',
            '/httpdocs/bilder',
            '/.ssh/authorized_keys',
            '/logs/access.log',

            // Ein Verzeichnis, das der Kunde selbst angelegt hat und das
            // zufällig heisst wie eines des Schemas. Es liegt eine Ebene
            // tiefer und gehört ihm.
            '/tmp/httpdocs',
            '/httpdocs/conf',
        ] as $pfad) {
            $this->assertFalse(
                Scheme::isFixed($pfad),
                sprintf('`%s` liegt im Schema und nicht darin — es gehört dem Kunden.', $pfad),
            );
        }
    }

    /**
     * Und die Abweisung nennt den Grund und was stattdessen geht.
     *
     * `docs/19 §6`: Eine Auskunft, die nur „darf nicht" sagt, lässt den Lesenden
     * mit der Frage zurück, was er denn tun soll. Hier ist die Antwort kurz und
     * gehört in denselben Satz.
     */
    public function test_the_refusal_says_what_is_still_possible(): void
    {
        try {
            Scheme::protect('/httpdocs', 'entfernt');

            $this->fail('`/httpdocs` liess sich entfernen.');
        } catch (AgentException $ausnahme) {
            $satz = $ausnahme->getMessage();

            $this->assertStringContainsString('/httpdocs', $satz, 'Die Abweisung nennt den Pfad nicht.');
            $this->assertStringContainsString('entfernt', $satz, 'Die Abweisung nennt nicht, was versucht wurde.');
            $this->assertStringContainsString(
                'Inhalt',
                $satz,
                'Die Abweisung sagt nicht, dass der Inhalt weiterhin änderbar ist. Ohne diesen '.
                'Halbsatz liest sich der Ordner wie gesperrt.',
            );
        }
    }

    /**
     * Die drei Operationen, die etwas zerstören können, fragen danach.
     *
     * **`chmod` ist die, die man vergisst**, und sie ist seit Schritt 6c die
     * gefährlichste von den dreien: `httpdocs` trägt das setgid-Bit, diese
     * Operation nimmt nur neun Bits entgegen — ein `chmod` des Kunden nähme das
     * zehnte lautlos weg, und die nächste hochgeladene Datei trüge wieder die
     * falsche Gruppe.
     */
    public function test_the_operations_that_can_destroy_ask_first(): void
    {
        foreach ([
            'FilesRemove' => 'entfernt',
            'FilesMove' => 'verschoben',
            'FilesChmod' => 'Rechten geändert',
        ] as $klasse => $verb) {
            $quelle = (string) file_get_contents(
                dirname(__DIR__, 2).'/agent/src/Ops/'.$klasse.'.php',
            );

            $this->assertStringContainsString(
                'Scheme::protect(',
                $quelle,
                sprintf('%s fragt das Schema nicht — der Kernel weist erst zu spät ab.', $klasse),
            );

            $this->assertStringContainsString(
                $verb,
                $quelle,
                sprintf('%s nennt in seiner Abweisung nicht, was versucht wurde.', $klasse),
            );
        }
    }

    /**
     * Und die Prüfung steht **vor** dem Eintritt in die Sandbox.
     *
     * Das ist der ganze Punkt. Innerhalb von `$workspace->run(...)` wäre sie
     * korrekt und wirkungslos: Der Kernel weist denselben Vorgang auch dort ab
     * — nur eben nach dem Leerräumen.
     */
    public function test_the_check_runs_before_anything_happens(): void
    {
        $quelle = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/FilesRemove.php',
        );

        $schutz = strpos($quelle, 'Scheme::protect(');
        $sandbox = strpos($quelle, '$workspace->run(');

        $this->assertNotFalse($schutz, 'FilesRemove fragt das Schema gar nicht.');
        $this->assertNotFalse($sandbox, 'FilesRemove betritt die Sandbox nicht mehr.');

        $this->assertLessThan(
            $sandbox,
            $schutz,
            'Die Prüfung steht innerhalb der Sandbox. Dann läuft sie, nachdem der Baumlauf '.
            'begonnen hat — und genau das ist der Fehler, gegen den sie gebaut wurde.',
        );
    }
}
