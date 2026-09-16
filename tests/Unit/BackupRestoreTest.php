<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\BackupRestore;

/**
 * Der Eigentümerwechsel nach dem Auspacken folgt keinem Verweis.
 *
 * ## Der Befund, für den es diesen Wächter gibt
 *
 * Gemessen am 16. September 2026 (`docs/117 §15` M12), bevor eine Zeile
 * entstand:
 *
 * | Griff | der Verweis | sein Ziel |
 * |---|---|---|
 * | `chown()` | bleibt, wie er war | **bekommt den neuen Eigentümer** |
 * | `lchown()` | bekommt ihn | bleibt, wie es war |
 *
 * `Backup\Unpacker` legt Verweise an und prüft ihr Ziel **mit Absicht nicht** —
 * was ein Kunde in seinem eigenen Baum anlegen darf, darf eine
 * Wiederherstellung ihm zurückgeben. Ein `chown -R` danach machte daraus einen
 * Weg nach draussen: ein Verweis auf `/etc/shadow` im Archiv, und nach der
 * Wiederherstellung gehört die Datei dem Kunden.
 *
 * > **Ein Verweis, dessen Ziel man nicht prüft, ist harmlos, solange niemand
 * > ihm folgt — und ein rekursiver Griff folgt ihm, ohne es zu sagen.**
 *
 * ## Warum er einen echten Baum baut
 *
 * Weil die Frage an den Kernel geht und nicht an unseren Quelltext. Ein Wächter,
 * der `lchown` als Wort im Code suchte, wäre grün, sobald es irgendwo steht —
 * und das ist in diesem Repo eine bezahlte Erfahrung.
 *
 * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
 * > steht.**
 *
 * ## Und was er nicht kann
 *
 * Er läuft als root. Ein Eigentümerwechsel, der einem **unprivilegierten**
 * Aufrufer scheitern würde, scheitert hier nicht — dieselbe Grenze, die
 * `BackupPromiseTest` für die Reihenfolge der Verzeichnisrechte benennt.
 */
final class BackupRestoreTest extends TestCase
{
    private string $scratch = '';

    /** Ein Benutzer, den es hier gibt und der nicht root ist. */
    private const WER = 'nobody';

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/backup-restore-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/baum', 0700, true);
        mkdir($this->scratch.'/draussen', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->scratch));

        parent::tearDown();
    }

    private function uidOf(string $wer): int
    {
        $eintrag = posix_getpwnam($wer);

        $this->assertIsArray($eintrag, sprintf('Den Benutzer %s gibt es hier nicht — dann misst dieser Fall nichts.', $wer));

        return (int) $eintrag['uid'];
    }

    /**
     * **Der Fall, um dessentwillen es diesen Wächter gibt.**
     *
     * Ein Verweis im Baum zeigt auf eine Datei ausserhalb. Nach dem
     * Eigentümerwechsel gehört der **Verweis** dem neuen Benutzer und das
     * **Ziel** weiterhin root.
     */
    public function test_the_owner_change_never_follows_a_link_out_of_the_tree(): void
    {
        $opfer = $this->scratch.'/draussen/opfer.txt';
        file_put_contents($opfer, 'gehoert root');
        chown($opfer, 'root');

        $verweis = $this->scratch.'/baum/verweis';
        symlink($opfer, $verweis);

        // Ohne diese Zeile misst der Fall nichts: Läge kein Verweis da, wäre
        // jedes Ergebnis darunter richtig.
        $this->assertTrue(is_link($verweis), 'Der Prüfkörper trägt keinen Verweis.');

        BackupRestore::own($this->scratch.'/baum', self::WER);

        $neu = $this->uidOf(self::WER);

        $this->assertSame($neu, lstat($verweis)['uid'], 'Der Verweis selbst hat den neuen Eigentümer nicht bekommen.');
        $this->assertSame(
            0,
            stat($opfer)['uid'],
            'Der Eigentümerwechsel ist dem Verweis gefolgt — damit gehört eine Datei ausserhalb des Abonnements dem Kunden.',
        );
    }

    /**
     * **Die Gegenprobe, und ohne sie belegt der Fall darüber nichts.**
     *
     * Ein `chown()` an derselben Stelle **muss** das Ziel treffen. Täte es das
     * nicht, wäre oben nicht `lchown` der Grund, sondern die Umgebung — und der
     * Wächter grün aus einem Grund, der mit seiner Regel nichts zu tun hat.
     */
    public function test_a_plain_chown_really_would_have_followed_it(): void
    {
        $opfer = $this->scratch.'/draussen/opfer.txt';
        file_put_contents($opfer, 'gehoert root');
        chown($opfer, 'root');

        symlink($opfer, $this->scratch.'/baum/verweis');

        chown($this->scratch.'/baum/verweis', self::WER);

        $this->assertSame(
            $this->uidOf(self::WER),
            stat($opfer)['uid'],
            'Ein gewöhnliches chown() folgt dem Verweis hier nicht — dann misst der Fall darüber etwas anderes als gedacht.',
        );
    }

    /**
     * Und der Rundlauf steigt nicht in ein verwiesenes Verzeichnis hinab.
     *
     * Dieselbe Familie, eine Ebene höher: `FOLLOW_SYMLINKS` am Iterator führte
     * ihn aus dem Baum hinaus, und dann träfe `chown()` jede Datei dahinter —
     * ganz ohne dass ein einziger Verweis gechownt würde.
     */
    public function test_the_walk_does_not_descend_into_a_linked_directory(): void
    {
        $drin = $this->scratch.'/draussen/unterordner';
        mkdir($drin, 0700, true);
        file_put_contents($drin.'/tief.txt', 'gehoert root');
        chown($drin.'/tief.txt', 'root');

        symlink($this->scratch.'/draussen', $this->scratch.'/baum/hinaus');

        BackupRestore::own($this->scratch.'/baum', self::WER);

        $this->assertSame(
            0,
            stat($drin.'/tief.txt')['uid'],
            'Der Rundlauf ist in ein verwiesenes Verzeichnis hinabgestiegen.',
        );
    }

    /** Gewöhnliche Dateien und Verzeichnisse bekommen ihn sehr wohl. */
    public function test_files_and_directories_do_get_the_new_owner(): void
    {
        mkdir($this->scratch.'/baum/httpdocs', 0755, true);
        file_put_contents($this->scratch.'/baum/httpdocs/index.php', '<?php');

        $gezaehlt = BackupRestore::own($this->scratch.'/baum', self::WER);

        $neu = $this->uidOf(self::WER);

        $this->assertSame($neu, stat($this->scratch.'/baum/httpdocs')['uid']);
        $this->assertSame($neu, stat($this->scratch.'/baum/httpdocs/index.php')['uid']);

        // Die Wurzel selbst bleibt aussen vor — sie gehört root, und das Schema
        // setzt sie gleich noch einmal.
        $this->assertSame(0, stat($this->scratch.'/baum')['uid']);

        $this->assertSame(2, $gezaehlt, 'Es werden nicht beide Einträge gezählt — dann misst der Fall wenig.');
    }

    /** Die Zusage von Form A: Die Operation ändert etwas, und sie heisst so. */
    public function test_it_is_a_mutating_operation(): void
    {
        $this->assertTrue(BackupRestore::mutating());
        $this->assertSame('backup.restore', BackupRestore::name());
    }
}
