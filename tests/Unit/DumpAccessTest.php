<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Db\Dump;
use SrvPanel\Agent\Ops\DbDumpCreate;
use Tests\Support\ReadsMethodSource;

/**
 * Das Panel kommt an die Datei, die es herunterladen soll.
 *
 * **Der Anlass ist ein 404 auf einer fertigen Sicherung**, gefunden am 8. August
 * 2026 auf `cloudsrv24`: „Herunterladen" antwortete mit *Not Found*, obwohl die
 * Datei da war und der Zustand „fertig" lautete.
 *
 * Die Datei lag als `root:srvpanel 0640` — die Gruppe des Panels durfte sie
 * lesen. Ihr **Verzeichnis** lag als `root:root 0750`, und damit fiel das Panel
 * in „andere": keine Rechte. Unter Unix öffnet man eine Datei aber über ihren
 * Pfad, und dafür braucht es das `x`-Bit auf **jedem** Verzeichnis darüber. Das
 * `r` an der Datei war wertlos.
 *
 * **Der Kommentar am Code hat den Fehler begründet, statt ihn zu verhindern.**
 * Dort stand: *„Nicht der Gruppe des Panels: Sie soll die Dateien lesen dürfen
 * und nicht das Verzeichnis durchsuchen."* Die Absicht ist richtig und gut
 * begründet — nur lässt sie sich so nicht ausdrücken. Wer nicht durchsuchen
 * darf, liest auch nicht. Und `docs/36 §10` hatte `root:srvpanel 0750`
 * dastehen: **Der Plan war richtig, die Umsetzung ist davon abgewichen** — mit
 * einem Kommentar, der die Abweichung erklärte, und ohne dass etwas den Bezug
 * geprüft hat.
 *
 * **Warum dieser Test rechnet statt abzuschreiben.** Ein Test, der
 * `assertSame(0710, Dump::DIRECTORY_MODE)` behauptet, ist die Zahl ein zweites
 * Mal und sagt nichts darüber, ob sie stimmt. Hier steht stattdessen die
 * Unix-Regel: Für jedes Verzeichnis des Pfades das `x`-Bit, für die Datei das
 * `r`-Bit, und beides für **dieselbe** Gruppe. Mit `root:root 0750` wäre der
 * erste Teil erfüllt gewesen und der letzte nicht — deshalb gehört die Gruppe
 * dazu und nicht nur der Modus.
 */
final class DumpAccessTest extends TestCase
{
    use ReadsMethodSource;

    /** Das Bit, das ein Verzeichnis durchsuchbar macht — für die Gruppe. */
    private const GROUP_EXECUTE = 0010;

    /** Das Bit, das eine Datei lesbar macht — für die Gruppe. */
    private const GROUP_READ = 0040;

    /** Alles, was „andere" bekommen könnten. */
    private const OTHERS = 0007;

    /**
     * Jedes Verzeichnis auf dem Weg ist für die Gruppe durchsuchbar.
     *
     * Wurzel und Abonnementverzeichnis tragen denselben Modus, und das ist
     * keine Sparsamkeit: Ein `x` auf dem einen nützt nichts, wenn es auf dem
     * anderen fehlt. Der Pfad wird ganz durchlaufen.
     */
    public function test_the_group_may_walk_down_to_the_file(): void
    {
        $this->assertNotSame(
            0,
            Dump::DIRECTORY_MODE & self::GROUP_EXECUTE,
            'Ohne das x-Bit kommt das Panel nicht in das Verzeichnis — und dann ist das r an der Datei wertlos.',
        );

        $this->assertNotSame(
            0,
            Dump::FILE_MODE & self::GROUP_READ,
            'Und lesen muss es sie auch dürfen.',
        );
    }

    /**
     * Aber auflisten darf es das Verzeichnis nicht.
     *
     * Das ist die Absicht, die im alten Kommentar stand und die richtig war:
     * Wer eine Sicherung herunterlädt, kennt ihren Namen aus dem Bestand. `--x`
     * heisst hingehen, wenn man den Namen kennt; `ls` bleibt verwehrt.
     */
    public function test_the_group_may_not_list_the_directory(): void
    {
        $this->assertSame(
            0,
            Dump::DIRECTORY_MODE & self::GROUP_READ,
            'Ein auflistbares Verzeichnis verrät, welche Sicherungen es gibt — auch die fremder Abonnements.',
        );
    }

    /** Und „andere" bekommen an keiner Stelle etwas. */
    public function test_nothing_is_open_to_others(): void
    {
        $this->assertSame(0, Dump::DIRECTORY_MODE & self::OTHERS);
        $this->assertSame(0, Dump::FILE_MODE & self::OTHERS);
    }

    /**
     * Und die Gruppe wird auch gesetzt — sonst gilt der Modus für `root`.
     *
     * **Das ist die Behauptung, die den Fehler gefunden hätte.** Die Bits oben
     * stimmten schon vorher; falsch war, wem sie gehörten. Ein Verzeichnis
     * `root:root 0750` gibt der Gruppe `root` alles und dem Panel nichts.
     */
    public function test_every_directory_belongs_to_the_group_that_reads_the_files(): void
    {
        $prepare = (string) $this->methodSource(Dump::class, 'prepare');

        $this->assertStringContainsString(
            'chgrp($path, self::GROUP)',
            $prepare,
            'Ein Modus ohne die passende Gruppe ist ein Modus für root.',
        );

        $this->assertStringContainsString(
            'chmod($path, self::DIRECTORY_MODE)',
            $prepare,
            'Und der Modus wird bei jedem Lauf gesetzt, damit eine ältere Installation sich berichtigt.',
        );

        // Beide Ebenen, nicht nur die untere: Die Schleife ist der Grund, warum
        // hier nicht zweimal dasselbe steht.
        $this->assertStringContainsString('[self::ROOT, $directory]', $prepare);
    }

    /** Dieselbe Gruppe an der Datei — sonst zeigen die beiden Enden aneinander vorbei. */
    public function test_the_file_belongs_to_the_same_group(): void
    {
        $handOver = (string) $this->methodSource(DbDumpCreate::class, 'handOver');

        $this->assertStringContainsString('chgrp($path, Dump::GROUP)', $handOver);
        $this->assertStringContainsString('chmod($path, Dump::FILE_MODE)', $handOver);
    }
}
