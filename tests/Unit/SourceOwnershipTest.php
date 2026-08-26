<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemSourcesToggle;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\Sources;
use Tests\Support\WithoutHashComments;
use Tests\Support\WithoutPhpComments;

/**
 * Geschrieben wird nur in Dateien, die das Panel angelegt hat.
 *
 * ## Warum das die eine Regel dieser Operation ist
 *
 * **Wer eine Paketquelle kontrolliert, kontrolliert jedes Paket.** Sie kann
 * eines mit höherer Fassungsnummer ausliefern, das ein beliebiges anderes
 * ersetzt — `libc6`, `openssh-server`, `srvpanel` selbst. Eine fremde Quelle
 * anzufassen ist damit nicht eine Handlung neben den anderen, sondern die, die
 * alle künftigen umfasst (`docs/81 §3`, Frage 1: **entschieden, nein**).
 *
 * ## Warum die Liste gegen die Paketierung gehalten wird
 *
 * Weil sie sonst eine Liste im Code wäre, die gegen nichts steht. Geschrieben
 * werden die beiden Dateien **von der Paketierung** — `packaging/php-source.sh`
 * und `packaging/install.sh` —, und läuft eine der beiden Seiten weg, liesse
 * sich die eigene Quelle nicht mehr schalten. Der Grund stünde in keiner
 * Meldung: Die Datei ist ja da, sie heisst nur anders.
 *
 * > **Eine Positivliste, die niemand gegen die Wirklichkeit hält, ist eine
 * > Liste und keine Grenze.**
 *
 * Dieselbe Naht wie in `PhpSourceUriTest`, und aus demselben Grund.
 */
final class SourceOwnershipTest extends TestCase
{
    use WithoutHashComments;
    use WithoutPhpComments;

    /** Wo die Paketierung ihre Quelldateien schreibt. */
    private const PACKAGING = ['packaging/php-source.sh', 'packaging/install.sh'];

    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Jede eigene Datei wird von der Paketierung wirklich geschrieben.
     *
     * Die eine Richtung: Was in der Liste steht, muss es geben.
     */
    public function test_every_owned_file_is_written_by_the_packaging(): void
    {
        $geschrieben = $this->writtenByPackaging();

        $this->assertNotSame([], $geschrieben, 'Der Ausdruck findet keine einzige Quelldatei — er misst nichts.');

        foreach (Sources::owned() as $eigen) {
            $this->assertContains($eigen, $geschrieben, sprintf(
                "%s steht in Sources::owned(), und keine Datei der Paketierung legt sie an.\n".
                'Entweder ist der Pfad veraltet, oder die Quelle entsteht woanders.',
                $eigen,
            ));
        }
    }

    /**
     * Und die Gegenrichtung: Was die Paketierung anlegt, steht in der Liste.
     *
     * **So entsteht die Lücke wirklich.** Kommt eine dritte Quelle dazu — der
     * Plan nennt `srvpanel-pgdg-source` —, trägt sie jemand in die Paketierung
     * ein und vergisst die Liste. Sie erscheint dann auf der Seite und lässt
     * sich als einzige nicht schalten, ohne dass irgendwo steht, warum.
     */
    public function test_every_source_the_packaging_writes_is_owned(): void
    {
        $eigen = Sources::owned();

        foreach ($this->writtenByPackaging() as $pfad) {
            $this->assertContains($pfad, $eigen, sprintf(
                "Die Paketierung legt %s an, und Sources::owned() kennt sie nicht.\n".
                'Sie erscheint damit auf der Seite und lässt sich als einzige nicht schalten.',
                $pfad,
            ));
        }
    }

    /**
     * Der Pfad von Sury steht einmal da.
     *
     * Er hat seit P1 eine Konstante; ein zweites Mal ausgeschrieben wäre die
     * Fassung, die beim Umbenennen stehenbleibt.
     */
    public function test_the_sury_path_is_not_written_twice(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents($this->repo().'/agent/src/Sources.php'));

        $this->assertStringContainsString('PhpVersions::SOURCE_FILE', $quelle);
        $this->assertStringNotContainsString('php-sury.sources', $quelle, sprintf(
            'Der Pfad steht schon als PhpVersions::SOURCE_FILE (%s) da.',
            PhpVersions::SOURCE_FILE,
        ));
    }

    /**
     * Die schaltende Operation fragt die Liste, und zwar bevor sie schreibt.
     *
     * **Geprüft wird die Reihenfolge und nicht das Vorkommen.** Ein
     * `isOwned()` hinter dem Schreiben ist kein Schutz, sondern eine Notiz.
     */
    public function test_the_toggle_asks_before_it_writes(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents($this->repo().'/agent/src/Ops/SystemSourcesToggle.php'),
        );

        $frage = strpos($quelle, 'Sources::isOwned(');
        $schreiben = strpos($quelle, '$this->write(');

        $this->assertIsInt($frage, 'Die Operation fragt Sources::isOwned() gar nicht.');
        $this->assertIsInt($schreiben, 'Die Operation schreibt gar nicht — dann misst dieser Wächter nichts.');
        $this->assertLessThan($schreiben, $frage, 'Die Frage nach dem Eigentum steht hinter dem Schreiben.');
    }

    /**
     * Nach dem Schreiben wird apt gefragt, und ein Fehlschlag rollt zurück.
     *
     * **Eine kaputte Quelldatei bricht nicht diese Quelle, sondern apt.**
     * Gemessen am 26. August 2026: Eine einzige unlesbare `.sources` lässt
     * `apt-get indextargets` und `apt-get -s upgrade` mit `rc=100` und null
     * Zeilen enden. Danach installiert dieser Server keine PHP-Version mehr.
     */
    public function test_a_failed_probe_rolls_the_file_back(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents($this->repo().'/agent/src/Ops/SystemSourcesToggle.php'),
        );

        $this->assertStringContainsString('self::PROBE', $quelle, 'Nach dem Schreiben wird apt nicht gefragt.');
        $this->assertContains('indextargets', SystemSourcesToggle::PROBE);

        // Der Rückweg steht im selben Zweig wie der Fehlschlag der Probe.
        $this->assertMatchesRegularExpression(
            '/if \(! \$probe->successful\(\)\) \{\s*\$this->write\(\$pfad, \$vorher\);/',
            $quelle,
            'Ein fehlgeschlagener apt-Aufruf nimmt die Änderung nicht zurück.',
        );
    }

    /**
     * Geschrieben wird daneben und dann umbenannt.
     *
     * Ein `file_put_contents()` auf die Zieldatei ist zwischen Kürzen und
     * Schreiben ein Zustand, in dem apt eine halbe Stanza liest — und dann
     * liest es gar nichts mehr.
     */
    public function test_the_write_is_atomic(): void
    {
        $quelle = $this->withoutComments(
            (string) file_get_contents($this->repo().'/agent/src/Ops/SystemSourcesToggle.php'),
        );

        $this->assertStringContainsString('rename(', $quelle, 'Geschrieben wird nicht über rename().');
        $this->assertSame(
            1,
            substr_count($quelle, 'file_put_contents('),
            'Es gibt mehr als eine schreibende Stelle — eine davon geht an rename() vorbei.',
        );

        /*
         * **Und geschrieben wird in die Wegwerfdatei, nicht in die Zieldatei.**
         * Der erste Wurf dieser Prüfung zählte nur die Aufrufe und fand `rename`
         * irgendwo — ein `file_put_contents($pfad, …)` daneben liess sie kalt.
         *
         * > **Ein Wächter, der zählt, wie oft geschrieben wird, hat über das
         * > Wohin nichts gesagt.**
         */
        $this->assertMatchesRegularExpression(
            '/file_put_contents\(\$neben,/',
            $quelle,
            'Geschrieben wird direkt in die Zieldatei — zwischen Kürzen und Schreiben liest apt eine halbe Stanza.',
        );
    }

    /**
     * Die Prüfung nimmt gleichwertige Schreibweisen derselben Datei an.
     *
     * **Gemessen, nachdem der Bruch dazu nicht gebissen hat.** Der erste Wurf
     * behauptete, `realpath()` fange einen symbolischen Verweis ab — es ist
     * andersherum: Ein Verweis **an** der eigenen Stelle wird vom
     * Zeichenkettenvergleich ohnehin angenommen. Was `realpath()` leistet, ist
     * die Annahme von `./` und `../` in derselben Datei.
     *
     * > **Eine Auflösung, die zwei Schreibweisen zusammenführt, ist keine
     * > Prüfung — sie ist eine Nachsicht.**
     */
    public function test_equivalent_spellings_of_the_same_file_are_accepted(): void
    {
        $mitPunkt = dirname(Sources::PANEL_SOURCE).'/./'.basename(Sources::PANEL_SOURCE);

        $this->assertTrue(
            Sources::isOwned($mitPunkt),
            'Dieselbe Datei, anders geschrieben, wird abgewiesen — dann fehlt die Auflösung.',
        );

        // Die Gegenprobe: Auflösen heisst nicht, alles anzunehmen.
        $this->assertFalse(Sources::isOwned(dirname(Sources::PANEL_SOURCE).'/./docker.list'));
    }

    /** Und was nicht in der Liste steht, kommt nicht hinein. */
    public function test_a_foreign_path_is_refused(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents($this->repo().'/agent/src/Sources.php'));

        $this->assertStringContainsString('realpath(', $quelle, 'isOwned() vergleicht nur Zeichenketten.');

        $this->assertFalse(Sources::isOwned('/etc/apt/sources.list.d/docker.list'));
        $this->assertFalse(Sources::isOwned('/etc/apt/sources.list.d/../../etc/passwd'));
        $this->assertFalse(Sources::isOwned(''));

        // Und die Gegenprobe — sonst hiesse „alles abgewiesen" auch „richtig".
        $this->assertTrue(Sources::isOwned(Sources::PANEL_SOURCE));
        $this->assertTrue(Sources::isOwned(PhpVersions::SOURCE_FILE));
    }

    /**
     * Die Pfade, die die Paketierung anlegt.
     *
     * @return list<string>
     */
    private function writtenByPackaging(): array
    {
        $gefunden = [];

        foreach (self::PACKAGING as $datei) {
            $quelle = $this->withoutHashComments((string) file_get_contents($this->repo().'/'.$datei));

            preg_match_all(
                '#^\s*cat\s*>\s*(?<pfad>/etc/apt/sources\.list\.d/[^\s]+)#m',
                $quelle,
                $treffer,
            );

            foreach ($treffer['pfad'] as $pfad) {
                $gefunden[] = $pfad;
            }
        }

        return array_values(array_unique($gefunden));
    }
}
