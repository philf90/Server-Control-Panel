<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Backup\Packer;
use SrvPanel\Agent\Backup\Unpacker;
use ZipArchive;

/**
 * Was das Verzeichnis über eine Datei sagt, kommt beim Entpacken wieder heraus.
 *
 * **Der Prüfkörper ist der aus `docs/116` M1b**, Eigenschaft für Eigenschaft:
 * eine Datei `0644`, ein privater Schlüssel `0600`, ein Symlink, ein
 * Verzeichnis mit **setgid** (`2750`) und ein **leeres** Verzeichnis (`0700`).
 * Das sind die fünf, von denen `ZipArchive` genau eine unverändert
 * zurückgibt — und deshalb gibt es das Verzeichnis.
 *
 * **Die Gegenprobe steht in diesem Wächter und nicht daneben.** Derselbe
 * Rundlauf ohne das Verzeichnis, also mit `extractTo()` allein, muss die
 * Verzeichnisse als `0777` zurückgeben. Ohne sie belegte der Test nur, dass ein
 * Rundlauf rechnet — nicht, dass das Verzeichnis dabei etwas beiträgt.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * ## Was dieser Wächter nicht halten kann
 *
 * Die **Reihenfolge**, in der `Unpacker` die Verzeichnisrechte setzt (tiefstes
 * zuerst). Gemessen am 16. September 2026: Nimmt ein `chmod` dem Elternteil das
 * `x`-Bit, scheitert das `chmod` am Kind — **aber nur für einen
 * unprivilegierten Aufrufer**; als root gelingt es. Dieser Wächter läuft als
 * root, der Agent auch, und damit ist der Fehler hier nicht herstellbar. Ein
 * Eingriff, der die Sortierung umdreht, bleibt grün.
 *
 * > **Ein Eingriff, der einen Zustand herstellt, den der Prüfling ohnehin
 * > gleich beantwortet, misst die Regel nicht — er misst, dass sie
 * > unempfindlich ist.**
 *
 * Deshalb steht sie hier als Frage und nicht als Zusage, und es gibt im
 * Bruchskript keinen Eingriff dafür.
 */
final class BackupPromiseTest extends TestCase
{
    private string $scratch = '';

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/srvpanel-backup-'.bin2hex(random_bytes(6));

        if (! mkdir($this->scratch, 0700, true)) {
            $this->fail('Der Wegwerfbaum liess sich nicht anlegen.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            exec('rm -rf '.escapeshellarg($this->scratch));
        }
    }

    /**
     * Der Prüfkörper aus M1b — und die drei Verzeichnisse, die draussen bleiben.
     *
     * Die Rechte werden **nach** dem Anlegen gesetzt: `mkdir()` verrechnet die
     * umask, `chmod()` nicht. Ohne diesen Schritt misst der Test die umask des
     * Prüfstands und nicht den Packer.
     */
    private function buildTree(): string
    {
        $root = $this->scratch.'/abo';

        mkdir($root.'/httpdocs/wp-content', 0700, true);
        mkdir($root.'/leer', 0700, true);
        mkdir($root.'/logs', 0700, true);
        mkdir($root.'/tmp', 0700, true);
        mkdir($root.'/conf', 0700, true);

        file_put_contents($root.'/httpdocs/index.php', "<?php\n");
        file_put_contents($root.'/httpdocs/.env', "APP_KEY=geheim\n");
        file_put_contents($root.'/logs/access.log', str_repeat("x\n", 100));
        file_put_contents($root.'/conf/beispiel.include', "# erzeugt\n");
        symlink('wp-content', $root.'/httpdocs/inhalt');

        chmod($root.'/httpdocs/index.php', 0644);
        chmod($root.'/httpdocs/.env', 0600);
        chmod($root.'/httpdocs/wp-content', 02750);
        chmod($root.'/httpdocs', 02750);
        chmod($root.'/leer', 0700);

        return $root;
    }

    /**
     * Der Rundlauf: gepackt, ausgepackt, und alle fünf Eigenschaften stehen wieder da.
     */
    public function test_the_manifest_carries_what_the_archive_loses(): void
    {
        $root = $this->buildTree();
        $archive = $this->scratch.'/sicherung.zip';

        $packed = Packer::packTree($root, $archive);

        $target = $this->scratch.'/zurueck';
        mkdir($target, 0700, true);

        Unpacker::unpack($archive, $target, $packed['entries']);

        $this->assertSame(0644, $this->modeOf($target.'/httpdocs/index.php'), 'Eine gewöhnliche Datei.');
        $this->assertSame(0600, $this->modeOf($target.'/httpdocs/.env'), 'Ein Geheimnis bleibt eines.');
        $this->assertSame(02750, $this->modeOf($target.'/httpdocs'), 'Und das setgid-Bit kommt mit.');
        $this->assertSame(02750, $this->modeOf($target.'/httpdocs/wp-content'));

        $this->assertDirectoryExists($target.'/leer', 'Ein leeres Verzeichnis ist auch eines.');
        $this->assertSame(0700, $this->modeOf($target.'/leer'));

        $this->assertTrue(is_link($target.'/httpdocs/inhalt'), 'Ein Verweis kommt als Verweis zurück.');
        $this->assertSame('wp-content', readlink($target.'/httpdocs/inhalt'));

        $this->assertSame("<?php\n", file_get_contents($target.'/httpdocs/index.php'));
    }

    /**
     * **Die Gegenprobe.** Ohne das Verzeichnis trägt das Archiv keinen Modus.
     *
     * Dasselbe Archiv, nur mit `extractTo()` statt mit dem Unpacker. Schlüge sie
     * nicht aus, trüge das Archiv die Rechte schon selbst — und dann wäre das
     * Verzeichnis Zierat.
     *
     * **Der tragende Vergleich ist der zwischen zwei Quellen und nicht eine
     * Zahl.** `extractTo()` legt jeden Eintrag mit `0777` beziehungsweise `0666`
     * gegen die **umask** an; welche Zahl herauskommt, hängt damit am Prüfstand
     * und nicht am Archiv (gemessen: umask `0022` gibt `0755`/`0644`, umask
     * `0000` gibt `0777`/`0666` — `docs/116` M1b, Nachtrag). Was auf jeder
     * Maschine gilt: `index.php` mit `0644` und `.env` mit `0600` kommen mit
     * **demselben** Modus zurück.
     *
     * > **Eine Anzeige, die zwei verschiedene Werte gleich aussehen lässt,
     * > behauptet etwas, das sie nicht weiss.**
     *
     * Die umask wird trotzdem festgehalten, damit die Zahl in einer
     * Fehlermeldung etwas bedeutet — `0022` ist die, unter der der Agent läuft
     * (`srvpanel-agentd.service` setzt kein `UMask=`).
     */
    public function test_without_the_manifest_the_modes_are_gone(): void
    {
        $root = $this->buildTree();
        $archive = $this->scratch.'/sicherung.zip';

        Packer::packTree($root, $archive);

        $target = $this->scratch.'/roh';
        mkdir($target, 0700, true);

        $vorher = umask(0022);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archive) === true);
            $zip->extractTo($target);
            $zip->close();
        } finally {
            umask($vorher);
        }

        $this->assertSame(
            $this->modeOf($target.'/httpdocs/index.php'),
            $this->modeOf($target.'/httpdocs/.env'),
            'Zwei Quellen mit verschiedenen Rechten kommen gleich zurück — das Archiv trägt den Modus nicht.',
        );

        $this->assertNotSame(
            0600,
            $this->modeOf($target.'/httpdocs/.env'),
            'Und der teurere der beiden Schäden: Ein privater Schlüssel käme lesbar für alle zurück.',
        );

        $this->assertSame(
            0,
            $this->modeOf($target.'/httpdocs') & 02000,
            'Das setgid-Bit kann eine umask nicht setzen — es fehlt, weil das Archiv es nicht trägt.',
        );

        $this->assertFalse(
            is_link($target.'/httpdocs/inhalt'),
            'Und ein Verweis kommt aus einem Zip nicht als Verweis zurück.',
        );
    }

    /**
     * Was draussen bleibt, bleibt draussen — und steht im Verzeichnis.
     *
     * Beide Richtungen: `logs`, `tmp` und `conf` fehlen, `httpdocs` und `leer`
     * sind da. Ohne die zweite Hälfte bestünde der Test auch bei einem Packer,
     * der gar nichts packt.
     */
    public function test_three_directories_stay_out_and_say_so(): void
    {
        $root = $this->buildTree();
        $packed = Packer::packTree($root, $this->scratch.'/sicherung.zip');

        $paths = array_column($packed['entries'], 'path');

        foreach (['logs', 'tmp', 'conf', 'logs/access.log', 'conf/beispiel.include'] as $out) {
            $this->assertNotContains($out, $paths, sprintf('%s gehört nicht in eine Sicherung.', $out));
        }

        foreach (['httpdocs', 'httpdocs/index.php', 'httpdocs/.env', 'leer'] as $in) {
            $this->assertContains($in, $paths, sprintf('%s schon.', $in));
        }

        $this->assertSame(
            array_keys(Packer::SKIPPED),
            array_keys($packed['skipped']),
            'Und was fehlt, steht im Verzeichnis — ein Archiv, das stillschweigend weniger enthält, ist das Problem.',
        );
    }

    /**
     * Ein Verweis wird notiert und nicht verfolgt.
     *
     * **Das ist die tragende Schranke dieser Klasse.** Der Agent läuft als root
     * über einen Baum, in den der Kunde schreibt; ein Verweis nach draussen
     * läge sonst im Archiv, das der Kunde herunterlädt.
     */
    public function test_a_link_out_of_the_tree_is_noted_and_not_followed(): void
    {
        $root = $this->buildTree();

        $secret = $this->scratch.'/fremd.txt';
        file_put_contents($secret, "was den Kunden nichts angeht\n");
        symlink($secret, $root.'/httpdocs/raus');

        // Und ein Verweis auf ein *Verzeichnis* ausserhalb — der gefährlichere
        // Fall: Ein Lauf, der ihm folgte, packte den ganzen fremden Baum ein.
        mkdir($this->scratch.'/fremdbaum', 0700, true);
        file_put_contents($this->scratch.'/fremdbaum/auch-geheim.txt', "ebenfalls nicht\n");
        symlink($this->scratch.'/fremdbaum', $root.'/httpdocs/rausdir');

        $packed = Packer::packTree($root, $this->scratch.'/sicherung.zip');
        $paths = array_column($packed['entries'], 'path');

        $this->assertContains('httpdocs/raus', $paths, 'Der Verweis selbst ist ein Eintrag.');
        $this->assertContains('httpdocs/rausdir', $paths);

        $this->assertNotContains(
            'httpdocs/rausdir/auch-geheim.txt',
            $paths,
            'Aber sein Inhalt nicht — sonst läge ein fremder Baum in der Sicherung des Kunden.',
        );

        // Und im Archiv liegt der Inhalt auch nicht.
        $zip = new ZipArchive;
        $zip->open($this->scratch.'/sicherung.zip');
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();

        $this->assertNotContains('httpdocs/raus', $names, 'Ein Verweis wird nicht als Datei gepackt.');

        foreach ($names as $name) {
            $this->assertStringNotContainsString('geheim', (string) $name);
        }
    }

    /**
     * Ein toter Verweis bringt die Sicherung nicht zu Fall.
     *
     * **Gemessen am 16. September 2026:** `SplFileInfo::getPerms()` wirft an
     * einem toten Verweis eine `RuntimeException` — ein Kunde mit einem
     * kaputten Symlink hätte damit jede Sicherung zum Absturz gebracht. Und an
     * einem heilen gibt es den Modus des **Ziels** zurück (`100600` für ein
     * Ziel mit `0600`), nicht den des Verweises.
     */
    public function test_a_dangling_link_does_not_kill_the_backup(): void
    {
        $root = $this->buildTree();
        symlink($root.'/gibt-es-nicht', $root.'/httpdocs/tot');

        $packed = Packer::packTree($root, $this->scratch.'/sicherung.zip');
        $paths = array_column($packed['entries'], 'path');

        $this->assertContains('httpdocs/tot', $paths, 'Er ist ein Eintrag wie jeder andere.');
    }

    /**
     * Und der Modus eines Verweises ist seiner und nicht der seines Ziels.
     *
     * Der Prüfkörper ist genau der gemessene Fall: ein Verweis auf eine Datei
     * mit `0600`. Stünde dort `0600`, setzte die Wiederherstellung den Modus am
     * **Ziel**, weil `chmod` einem Verweis folgt.
     */
    public function test_a_link_carries_its_own_mode_and_not_its_targets(): void
    {
        $root = $this->buildTree();
        symlink('.env', $root.'/httpdocs/zeigt-auf-geheim');

        $packed = Packer::packTree($root, $this->scratch.'/sicherung.zip');

        foreach ($packed['entries'] as $entry) {
            if ($entry['path'] === 'httpdocs/zeigt-auf-geheim') {
                $this->assertSame('0777', $entry['mode'], 'Ein Symlink trägt unter Linux immer 0777.');

                return;
            }
        }

        $this->fail('Der Verweis fehlt im Verzeichnis.');
    }

    /**
     * Ein Name, den die Sicherung selbst belegt, bricht den Lauf — laut.
     *
     * **Gemessen am 16. September 2026:** `ZipArchive::addFromString()` auf
     * einen Namen, den `addFile()` schon geschrieben hat, ersetzt ihn
     * **wortlos** — ein Eintrag statt zwei, `close()` gibt `true`, und beim
     * Auspacken liegt unsere Fassung da. Die Datei des Kunden wäre aus seiner
     * eigenen Sicherung fort.
     *
     * Der Fehler fiele erst beim Zurückspielen auf, weil `Unpacker` die fehlende
     * Datei meldet — also dann, wenn der Kunde schon darauf wartet.
     *
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        yield 'das Verzeichnis' => [Manifest::ENTRY];
        yield 'der Ort der Dumps' => [Manifest::DUMPS];
    }

    #[DataProvider('reservedNames')]
    public function test_a_name_the_backup_owns_stops_the_run(string $name): void
    {
        $root = $this->buildTree();
        file_put_contents($root.'/'.$name, "die Datei des Kunden\n");

        $this->expectException(AgentException::class);

        Packer::packTree($root, $this->scratch.'/sicherung.zip');
    }

    /**
     * **Die Gegenprobe**, und sie misst die Form der Frage.
     *
     * Gefragt wird am ersten Namensteil und nicht mit `str_starts_with()`. Ein
     * Name, der nur so *anfängt*, gehört dem Kunden und kommt durch — sonst
     * wüchse die Regel mit jedem Namen, der zufällig ähnlich aussieht.
     */
    public function test_a_name_that_only_looks_reserved_passes(): void
    {
        $root = $this->buildTree();
        file_put_contents($root.'/'.Manifest::DUMPS.'-alt', "gehört dem Kunden\n");
        file_put_contents($root.'/httpdocs/'.Manifest::ENTRY, "auch\n");

        $packed = Packer::packTree($root, $this->scratch.'/sicherung.zip');
        $paths = array_column($packed['entries'], 'path');

        $this->assertContains(Manifest::DUMPS.'-alt', $paths);
        $this->assertContains(
            'httpdocs/'.Manifest::ENTRY,
            $paths,
            'Belegt ist der Name an der Wurzel — eine Datei desselben Namens eine Ebene tiefer kollidiert nicht.',
        );
    }

    /**
     * Was der Sicherung gehört, landet nicht im Baum des Kunden.
     *
     * Die Datenbankdumps liegen im Archiv unter {@see Manifest::DUMPS}, also
     * ausserhalb des Kundenbaums — so, wie sie auf dem Server ausserhalb
     * liegen. Packte der Unpacker sie mit aus, fände ein Kunde nach seiner
     * Wiederherstellung ein `.srvpanel-databases/` mitten in seinen Dateien,
     * das er nie hatte; die Wiederherstellung holt sie sich einzeln.
     *
     * **Gefragt wird mit derselben Methode wie beim Packen** — ein zweiter
     * Ausdruck an dieser Stelle wäre die zweite Fassung derselben Regel, und
     * die zweite ist die, die veraltet (`docs/81 §2.3o` M22).
     *
     * Das Archiv wird hier so gebaut, wie `backup.create` es baut: packen,
     * wieder öffnen, die Dumps hineinlegen. Ein Prüfkörper, der sie anders
     * hineinbrächte, prüfte eine andere Form als die des Prüflings.
     */
    public function test_what_belongs_to_the_backup_never_reaches_the_customers_tree(): void
    {
        $root = $this->buildTree();
        $archive = $this->scratch.'/sicherung.zip';

        $packed = Packer::packTree($root, $archive);

        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addEmptyDir(Manifest::DUMPS);
        $zip->addFromString(Manifest::DUMPS.'/shop_wp.sql.gz', 'der Inhalt einer Datenbank');
        $zip->addFromString(Manifest::ENTRY, '{"format":1}');
        $zip->close();

        $entries = $packed['entries'];
        $entries[] = Manifest::entry(Manifest::DUMPS, Manifest::KIND_DIRECTORY, 0700);
        $entries[] = Manifest::entry(Manifest::DUMPS.'/shop_wp.sql.gz', Manifest::KIND_FILE, 0640);

        $target = $this->scratch.'/zurueck';
        mkdir($target, 0700, true);

        // Ohne die Regel wirft dieser Aufruf schon: Die Dumps stünden im
        // Verzeichnis und wären nicht ausgepackt, also fehlten sie.
        Unpacker::unpack($archive, $target, $entries);

        $this->assertDirectoryDoesNotExist(
            $target.'/'.Manifest::DUMPS,
            'Die Datenbanken der Sicherung gehören nicht in den Baum des Kunden.',
        );

        $this->assertFileDoesNotExist($target.'/'.Manifest::ENTRY, 'Und das Verzeichnis auch nicht.');

        // **Die Gegenprobe**: Der Rest des Baums ist trotzdem da. Ohne sie
        // bestünde der Fall auch bei einem Unpacker, der gar nichts auspackt.
        $this->assertFileExists($target.'/httpdocs/index.php');
        $this->assertSame(02750, $this->modeOf($target.'/httpdocs'));
    }

    /** Die zwölf Bits, die `chmod` setzt — ohne die Art der Datei. */
    private function modeOf(string $path): int
    {
        clearstatcache(true, $path);

        return ((int) fileperms($path)) & 07777;
    }
}
