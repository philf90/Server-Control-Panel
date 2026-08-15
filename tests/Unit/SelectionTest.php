<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Files\Packer;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Ops\FilesList;

/**
 * Eine Auswahl ist mehr als eine Liste von Pfaden.
 *
 * Zwei Regeln, die mit der Mehrfachauswahl (P6 Schritt 5h) entstanden sind und
 * beide auf demselben Muster stehen: **Zwei Dinge, die für einen Eintrag gleich
 * aussehen, sind es bei mehreren nicht mehr.**
 *
 * 1. `/a` und `/a/` bezeichnen dasselbe. Bei einem Eintrag ist das eine
 *    Schreibweise; bei zwanzig ist es ein Vorgang, der denselben Eintrag zweimal
 *    anfasst und beim zweiten Mal „gibt es nicht" meldet — mitten in einer
 *    Rückmeldung, die von siebzehn Erfolgen und drei Fehlschlägen spricht.
 *
 * 2. `/a/notizen` und `/b/notizen` heissen im Archiv beide `notizen`. Ein Zip
 *    nimmt das an, und beim Entpacken bleibt eines der beiden übrig.
 *
 * > **Ein Archiv, das stillschweigend weniger enthält, als hineingelegt wurde,
 * > ist derselbe Fehler wie ein Upload, der zwanzig Dateien unter einen Namen
 * > schreibt.**
 */
final class SelectionTest extends TestCase
{
    public function test_two_spellings_of_the_same_path_count_once(): void
    {
        $this->assertSame(
            ['/httpdocs/a.php', '/httpdocs/bilder'],
            Workspace::paths(['/httpdocs/a.php', '//httpdocs/./a.php', '/httpdocs/bilder/']),
            'Dieselbe Stelle in zwei Schreibweisen wird zweimal angefasst.',
        );
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(AgentException::class);

        Workspace::paths([]);
    }

    public function test_a_selection_that_is_not_a_list_is_refused(): void
    {
        $this->expectException(AgentException::class);

        Workspace::paths('/httpdocs');
    }

    /**
     * Mehr Einträge, als eine Liste zeigt, kann niemand angehakt haben.
     *
     * **Keine Schranke, sondern eine Grenze für die Nutzlast.** Die Deutung
     * jedes einzelnen Pfades entscheidet das Chroot; was hier abgewiesen wird,
     * ist ein Aufruf, den ohnehin niemand gemeint hat.
     */
    public function test_more_entries_than_a_listing_shows_are_refused(): void
    {
        $zuviele = array_map(
            static fn (int $i): string => '/httpdocs/'.$i,
            range(0, FilesList::MAX_ENTRIES),
        );

        $this->expectException(AgentException::class);

        Workspace::paths($zuviele);
    }

    /**
     * Zwei gleich heissende Quellen werden abgewiesen, und die Absage nennt den
     * Namen.
     */
    public function test_two_sources_of_the_same_name_are_refused(): void
    {
        $ziel = sys_get_temp_dir().'/srvpanel-auswahl-'.bin2hex(random_bytes(8)).'.zip';

        try {
            Packer::zip(['/a/notizen', '/b/notizen'], $ziel);

            $this->fail('Zwei gleich heissende Quellen sind angenommen worden.');
        } catch (AgentException $ausnahme) {
            $this->assertSame(AgentException::BAD_REQUEST, $ausnahme->errorCode);

            $this->assertStringContainsString(
                'notizen',
                $ausnahme->getMessage(),
                'Die Absage nennt den Namen nicht, an dem sie hängt.',
            );
        } finally {
            @unlink($ziel);
        }
    }

    /**
     * Und die Namen werden geprüft, **bevor** das Archiv geöffnet wird.
     *
     * ## Der erste Wurf dieses Falls konnte nie rot werden
     *
     * Er behauptete, ein zu spät geprüfter Name hinterlasse „eine halbe Datei",
     * und prüfte das mit `assertFileDoesNotExist`. **Gemessen am 15. August 2026:
     * `ZipArchive::open()` mit `CREATE|EXCL` legt nichts an, und ein Archiv ohne
     * Eintrag schreibt libzip auch beim `close()` nicht.** Die Behauptung war
     * falsch, und die Prüfung darüber war grün, egal wo die Namensprüfung stand.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist keine.**
     *
     * Was wirklich davon abhängt, ist die **Begründung**: Liegt am Ziel schon
     * eine Datei, scheitert `open()` mit „liess sich nicht anlegen" — und der
     * Kunde räumt das Ziel weg und läuft in denselben Fehler, weil seine Auswahl
     * immer noch zwei gleich heissende Einträge enthält.
     */
    public function test_the_names_are_checked_before_the_archive_is_opened(): void
    {
        $ziel = sys_get_temp_dir().'/srvpanel-auswahl-'.bin2hex(random_bytes(8)).'.zip';

        file_put_contents($ziel, 'belegt');

        try {
            Packer::zip(['/a/notizen', '/b/notizen'], $ziel);

            $this->fail('Zwei gleich heissende Quellen sind angenommen worden.');
        } catch (AgentException $ausnahme) {
            $this->assertStringContainsString(
                'notizen',
                $ausnahme->getMessage(),
                "Die Absage nennt das belegte Ziel statt der gleich heissenden Einträge.\n\n".
                'Von zwei Gründen gehört der genannt, den der nächste Versuch nicht von selbst '.
                'behebt: Wer das Ziel wegräumt, läuft in denselben Fehler.',
            );
        } finally {
            @unlink($ziel);
        }
    }
}
