<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Zwei Wege, die dieselbe Datei anlegen, müssen sie gleich anlegen.
 *
 * ## Der Anlass
 *
 * Auf `cloudsrv24` standen am 14. August 2026 zwei Dateien nebeneinander im
 * selben `httpdocs`:
 *
 * ```
 * -rw-r-----  1 1004   33  index.html       ← beim Anlegen des Abonnements
 * -rw-r--r--  1 1004 1004  p6-probe.txt     ← über den Dateimanager
 * ```
 *
 * `33` ist `www-data`, und **der Webserver kommt über die Gruppe hinein**
 * (`httpdocs` gehört `%u:www-data 0750`). Die zweite Datei war für ihn nur über
 * das **Weltbit** lesbar. Setzt ein Kunde sie auf `0640` — dieselbe Angabe, die
 * `index.html` daneben trägt und die dort funktioniert —, bekommt er einen 403,
 * und die Rechteanzeige des Panels zeigt die Spalte gar nicht, in der die
 * Erklärung steht (`docs/53`, Befund 3).
 *
 * ## Was hier geprüft wird
 *
 * Das setgid-Bit an den Verzeichnissen des Kunden. Es steht **einmal** am
 * Verzeichnis statt als `chgrp` in zwölf Operationen und in SFTP noch einmal:
 *
 * > **Eine Regel, die an jeder Schreibstelle einzeln stehen müsste, fehlt an
 * > der dreizehnten.**
 *
 * Gefragt wird über Reflexion an `SubscriptionProvision::TREE` und nicht am
 * laufenden System — dieser Container hat kein `/var/www/vhosts`, und die
 * Angabe ist eine Eigenschaft der Tabelle. Dass sie auch ankommt, misst der
 * Abnahmelauf auf einem echten Server.
 */
final class InheritedGroupTest extends TestCase
{
    /** Das Bit, um das es geht. */
    private const SETGID = 0o2000;

    /**
     * Das Schema, wie es im Agenten steht.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    private function tree(): array
    {
        $spiegel = new \ReflectionClass(SubscriptionProvision::class);

        /** @var array<string, array{0: string, 1: string, 2: int}> $tree */
        $tree = $spiegel->getConstant('TREE');

        return $tree;
    }

    /**
     * Jedes Verzeichnis, das dem Kunden gehört, vererbt seine Gruppe.
     *
     * **Auch die, bei denen es heute nichts ändert.** `tmp`, `.ssh` und `mail`
     * haben Eigentümer und Gruppe gleich; dort ist das Bit ein no-op. Es steht
     * trotzdem da, damit die Regel „alle Verzeichnisse des Kunden" heisst und
     * nicht „die mit einer fremden Gruppe" — die zweite müsste bei jedem
     * Zuwachs des Schemas neu beurteilt werden, und genau das passiert nicht.
     */
    public function test_every_directory_of_the_customer_inherits_its_group(): void
    {
        $geprueft = 0;

        foreach ($this->tree() as $pfad => [$owner, $group, $mode]) {
            if ($owner === 'root') {
                continue;
            }

            $geprueft++;

            $this->assertSame(
                self::SETGID,
                $mode & self::SETGID,
                sprintf(
                    "`%s` gehört dem Kunden und trägt kein setgid (%s).\n\n".
                    "Damit bekommt alles, was darin entsteht, die Gruppe seines Erzeugers statt\n".
                    "der des Verzeichnisses — und zwei Wege, die dieselbe Datei anlegen, legen sie\n".
                    "verschieden an. Auf `httpdocs` heisst das: Der Webserver kommt an die eine\n".
                    'Datei über die Gruppe und an die andere nur über das Weltbit.',
                    $pfad,
                    decoct($mode),
                ),
            );
        }

        /*
         * **Die Untergrenze, und sie zählt die Verzeichnisse des Kunden.**
         * Liest die Reflexion ins Leere — umbenannte Konstante, geänderte Form
         * der Tabelle —, prüft dieser Wächter null Einträge und ist grün.
         */
        $this->assertGreaterThan(
            3,
            $geprueft,
            'Dieser Wächter findet fast keine Verzeichnisse des Kunden. Dann liest er '.
            '`SubscriptionProvision::TREE` nicht mehr, und seine Zusage ist wertlos.',
        );
    }

    /**
     * Und `conf` trägt es nicht.
     *
     * Es gehört root, der Kunde legt dort nichts an, und ein setgid-Bit an
     * einem Verzeichnis, in das niemand schreibt, wäre eine Angabe ohne
     * Wirkung — also eine, die beim nächsten Lesen erklärt werden müsste.
     */
    public function test_the_directory_of_the_operator_does_not(): void
    {
        $tree = $this->tree();

        $this->assertArrayHasKey('conf', $tree, 'Das Schema kennt `conf` nicht mehr.');
        $this->assertSame('root', $tree['conf'][0], '`conf` gehört nicht mehr root.');
        $this->assertSame(0, $tree['conf'][2] & self::SETGID, '`conf` trägt setgid, und das hat dort keine Wirkung.');
    }

    /**
     * Das ausgelieferte Verzeichnis wird an einer Stelle beschrieben.
     *
     * **Bis zum 14. August standen `'www-data'` und `0750` zweimal da** — in
     * `SubscriptionProvision::TREE` und noch einmal als Literal in
     * `WebSiteApply::directories()`. Das setgid-Bit wäre an der zweiten Stelle
     * nicht angekommen, und für jede weitere Domain hätte weiter das Alte
     * gegolten.
     *
     * > **Eine Angabe, die zweimal dasteht, ändert man einmal.**
     */
    public function test_the_document_root_is_described_in_one_place(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/WebSiteApply.php');

        $this->assertStringContainsString(
            'SubscriptionProvision::DOCUMENT_ROOT_MODE',
            $quelle,
            'WebSiteApply legt das ausgelieferte Verzeichnis an, ohne die Angabe aus '.
            'SubscriptionProvision zu benutzen. Dann gibt es sie zweimal.',
        );

        $this->assertStringContainsString(
            'SubscriptionProvision::DOCUMENT_ROOT_GROUP',
            $quelle,
            'WebSiteApply nennt die Gruppe des ausgelieferten Verzeichnisses selbst.',
        );

        $this->assertStringNotContainsString(
            "'www-data', 0o750",
            $quelle,
            'In WebSiteApply steht die alte Angabe als Literal.',
        );
    }

    /**
     * Und sie erreicht auch den Bestand.
     *
     * **Der Fehler, der beinahe passiert wäre:** `directories()` setzte
     * Eigentümer, Gruppe und Rechte nur dann, wenn das Verzeichnis **neu
     * entstand**. Das setgid-Bit hätte damit kein einziges bestehendes
     * Abonnement erreicht — es wäre bei der nächsten neuen Domain aufgetaucht
     * und hätte für alle anderen weiter gefehlt.
     *
     * > **Eine Regel, die nur beim Anlegen gilt, erreicht den Bestand nie.**
     */
    public function test_the_rule_reaches_existing_subscriptions(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/WebSiteApply.php');

        $this->assertStringNotContainsString(
            '! is_dir($documentRoot)) {
            Filesystem::directory(',
            $quelle,
            'Das ausgelieferte Verzeichnis bekommt seine Angabe nur beim Anlegen. '.
            'Dann erreicht eine Änderung daran kein bestehendes Abonnement.',
        );

        $this->assertStringNotContainsString(
            '! is_dir($site->logDir())) {
            Filesystem::directory(',
            $quelle,
            'Dasselbe für das Protokollverzeichnis.',
        );
    }
}
