<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Backup\Manifest;
use SrvPanel\Agent\Ops\BackupVerify;
use ZipArchive;

/**
 * Was `backup.verify` an einem beschädigten Archiv findet — und was nicht.
 *
 * ## Warum dieser Wächter ein Archiv baut statt eines nachzustellen
 *
 * Weil die Frage lautet, welchen Schaden die Prüfung **findet**, und das hängt
 * an libzip und nicht an unserem Quelltext. Gemessen am 16. September 2026
 * (`docs/117 §13` M8): Jeden Eintrag zu lesen findet ein gekipptes Byte
 * **nicht** — `getStream()` gibt die entpackten Bytes zurück, ohne die
 * Prüfsumme zu vergleichen.
 *
 * > **Eine Prüfung, die teurer ist, ist deshalb nicht gründlicher — und welche
 * > Schäden sie findet, sagt erst der Prüfkörper, der sie herstellt.**
 *
 * Genau das steht hier als eigener Fall
 * ({@see self::test_reading_every_entry_would_not_have_found_it()}): die
 * Gegenprobe zur Bauart. Ohne sie belegte dieser Wächter, dass eine Prüfung
 * rechnet — nicht, dass sie die richtige ist.
 *
 * ## Warum die Einträge unkomprimiert abgelegt werden
 *
 * Damit ein Byte im Archiv **gezielt** gekippt werden kann: In einem
 * deflationierten Eintrag steht der Inhalt nicht wörtlich, und ein Eingriff
 * träfe irgendetwas. Die Prüfung selbst unterscheidet die beiden nicht — sie
 * vergleicht eine Prüfsumme, und die steht bei beiden im Verzeichnis des
 * Archivs.
 *
 * **Was dieser Wächter deshalb nicht sagt:** wie sich ein *deflationierter*
 * Eintrag mit gekipptem Byte verhält. Er wird beim Lesen meist schon abbrechen;
 * das ist ein anderer Weg zum selben Befund und hier nicht gemessen.
 */
final class BackupVerifyTest extends TestCase
{
    /*
     * **Kein `{@see \App\…}` in diesem Kopf, und das ist kein Stilgeschmack.**
     * Pint macht aus einem vollqualifizierten Verweis im Dokumentblock einen
     * `use`-Eintrag (`fully_qualified_strict_types`) — hier ist genau das
     * passiert. Damit trüge ein framework-freier Wächter einen `App\`-Import,
     * den er nie benutzt, und liefe im Wegwerf-Gestell dieses Containers nicht
     * mehr. Klassennamen aus `app/` stehen deshalb in Backticks.
     *
     * > **Ein Wächter, den man vor dem Formatierer prüft, ist nicht der, der
     * > ins Repo geht.**
     *
     * Er steht als `/* *\/` und nicht als Dokumentblock: In einem `/** *\/`
     * würde derselbe Fixer den Verweis darin wieder einsammeln — Backticks
     * halten ihn nicht auf.
     */

    private string $scratch = '';

    /** Wie viele Archive dieser Fall schon gebaut hat — je eines ein Name. */
    private int $gebaut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/backup-verify-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->scratch.'/*') as $pfad) {
            if (is_string($pfad)) {
                @unlink($pfad);
            }
        }

        @rmdir($this->scratch);

        parent::tearDown();
    }

    /** Der Inhalt einer Kundendatei — mit einer Marke, die sich wiederfinden lässt. */
    private const MARKE = 'MARKE-EINS-EINDEUTIG';

    /** Und der eines Dumps, der unter {@see Manifest::DUMPS} liegt. */
    private const DUMP_MARKE = 'MARKE-DUMP-EINDEUTIG';

    /**
     * Ein Archiv, wie `BackupCreate` es schreibt: Dateien, ein Dump, ein
     * Verzeichnis.
     *
     * @param  list<string>  $weglassen  Einträge, die nicht ins Archiv kommen — aber ins Verzeichnis
     */
    private function archive(array $weglassen = [], bool $ohneManifest = false, int $format = Manifest::FORMAT): string
    {
        // **Je Aufruf ein eigener Name.** Mit einem festen scheiterte der zweite
        // Aufruf in derselben Methode an `EXCL`, und `$zip` blieb uninitialisiert
        // — ein `ValueError` weit später, der wie ein Befund am Prüfling aussah.
        $pfad = sprintf('%s/sicherung-%d.zip', $this->scratch, ++$this->gebaut);

        $inhalte = [
            'httpdocs/index.php' => '<?php echo "'.self::MARKE.'";',
            'httpdocs/bild.png' => str_repeat("\x89PNG", 64),
            Manifest::DUMPS.'/shop.sql.gz' => 'gzip-'.self::DUMP_MARKE.'-'.str_repeat('x', 200),
        ];

        $zip = new ZipArchive;

        // Ohne diese Zeile baut ein gescheitertes `open()` wortlos kein Archiv,
        // und der Fall daneben misst eine Datei, die es nicht gibt.
        $this->assertTrue($zip->open($pfad, ZipArchive::CREATE | ZipArchive::EXCL), 'Das Prüfarchiv liess sich nicht anlegen.');

        $entries = [];

        foreach ($inhalte as $name => $inhalt) {
            $entries[] = Manifest::entry($name, Manifest::KIND_FILE, 0644);

            if (in_array($name, $weglassen, true)) {
                continue;
            }

            $zip->addFromString($name, $inhalt);
            // Unkomprimiert, damit die Marke im Archiv wörtlich dasteht.
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }

        if (! $ohneManifest) {
            $json = Manifest::encode('shop', 1001, 'p1001', 'Quellbaum', $entries, []);

            if ($format !== Manifest::FORMAT) {
                $daten = json_decode($json, true);
                $daten['format'] = $format;
                $json = (string) json_encode($daten);
            }

            $zip->addFromString(Manifest::ENTRY, $json);
        }

        $zip->close();

        return $pfad;
    }

    /** Ein Byte an der Stelle einer Marke kippen — im rohen Archiv. */
    private function flip(string $pfad, string $marke): void
    {
        $roh = (string) file_get_contents($pfad);
        $stelle = strpos($roh, $marke);

        // **Ohne diese Zeile misst der Eingriff nichts.** Findet er die Marke
        // nicht, bleibt die Datei heil, und der Fall darunter meldete „kein
        // Befund" für ein Archiv, das niemand beschädigt hat.
        $this->assertNotFalse($stelle, 'Die Marke steht nicht im Archiv — dann kippt dieser Eingriff nichts.');

        $roh[$stelle] = chr(ord($roh[$stelle]) ^ 0xFF);
        file_put_contents($pfad, $roh);
    }

    /**
     * Die Gründe eines Urteils, in der Reihenfolge, in der sie stehen.
     *
     * @param  array{findings: list<array{subject: string, reason: string, detail: null|string}>, ...}  $urteil
     * @return list<string>
     */
    private function reasons(array $urteil): array
    {
        return array_map(static fn (array $f): string => $f['reason'], $urteil['findings']);
    }

    public function test_a_healthy_archive_is_healthy(): void
    {
        $urteil = BackupVerify::verify($this->archive(), 'shop-20260916-101500-abcdef01');

        $this->assertSame([], $urteil['findings']);
        $this->assertTrue($urteil['healthy']);

        // **Vier und nicht drei**: die drei Dateien plus das Verzeichnis selbst.
        // Seine Bytes werden mitgeprüft — ein gekipptes Byte darin änderte einen
        // Modus oder einen Pfad, ohne dass `decode()` etwas merkt.
        $this->assertSame(4, $urteil['entries']);
        $this->assertGreaterThan(0, $urteil['bytes']);
        $this->assertSame('shop-20260916-101500-abcdef01', $urteil['storage']);
    }

    /**
     * **Der Befund, der diesen Wächter ausgelöst hat.**
     *
     * Der erste Wurf von {@see BackupVerify} hat die Dumps über
     * {@see Manifest::reserves()} herausgefiltert — so wie `Packer` und
     * `Unpacker` es tun, dort zu Recht. Hier hätte es geheissen: Eine
     * Sicherung, der **jede** Datenbank fehlt, wird als heil gemeldet.
     *
     * > **Dieselbe Frage an zwei Stellen hat nicht dieselbe Antwort, wenn die
     * > Stellen verschiedene Gegenstände haben — und die übernommene Zeile
     * > sieht aus wie Sorgfalt.**
     */
    public function test_a_missing_database_dump_is_a_finding(): void
    {
        $urteil = BackupVerify::verify($this->archive([Manifest::DUMPS.'/shop.sql.gz']), 'shop-x');

        $this->assertSame([BackupVerify::ENTRY_MISSING], $this->reasons($urteil));
        $this->assertFalse($urteil['healthy']);
        $this->assertStringContainsString(Manifest::DUMPS.'/shop.sql.gz', (string) $urteil['findings'][0]['detail']);
    }

    /** Und dieselbe Frage an den Bytes: Ein Dump wird wirklich gelesen. */
    public function test_a_flipped_byte_in_a_dump_is_a_finding(): void
    {
        $pfad = $this->archive();
        $this->flip($pfad, self::DUMP_MARKE);

        $urteil = BackupVerify::verify($pfad, 'shop-x');

        $this->assertSame([BackupVerify::CORRUPT], $this->reasons($urteil));
        $this->assertStringContainsString('shop.sql.gz', (string) $urteil['findings'][0]['detail']);
    }

    public function test_a_flipped_byte_in_a_customer_file_is_a_finding(): void
    {
        $pfad = $this->archive();
        $this->flip($pfad, self::MARKE);

        $urteil = BackupVerify::verify($pfad, 'shop-x');

        $this->assertSame([BackupVerify::CORRUPT], $this->reasons($urteil));
        $this->assertStringContainsString('httpdocs/index.php', (string) $urteil['findings'][0]['detail']);
    }

    /**
     * **Die Gegenprobe zur Bauart, und ohne sie belegt dieser Wächter nichts.**
     *
     * Derselbe Schaden, mit der naheliegenden Prüfung gemessen: jeden Eintrag
     * lesen. Sie kommt **ohne Fehler durch** — und wer sie gebaut hätte, hätte
     * eine teurere Prüfung, die genau den Schaden nicht sieht, vor dem eine
     * Sicherung schützen soll.
     */
    public function test_reading_every_entry_would_not_have_found_it(): void
    {
        $pfad = $this->archive();
        $this->flip($pfad, self::MARKE);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($pfad));

        $unlesbar = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if ($zip->getFromIndex($i) === false) {
                $unlesbar++;
            }
        }

        $zip->close();

        $this->assertSame(0, $unlesbar, 'Jeden Eintrag zu lesen findet ein gekipptes Byte — dann ist die Begründung im Kopf von BackupVerify falsch.');

        // Und derselbe Prüfkörper durch den Prüfling: **ein** Befund.
        $this->assertSame([BackupVerify::CORRUPT], $this->reasons(BackupVerify::verify($pfad, 'shop-x')));
    }

    public function test_an_entry_the_manifest_does_not_know_is_a_finding(): void
    {
        $pfad = $this->archive();

        $zip = new ZipArchive;
        $zip->open($pfad);
        $zip->addFromString('httpdocs/untergeschoben.php', '<?php /* nicht im Verzeichnis */');
        $zip->close();

        $urteil = BackupVerify::verify($pfad, 'shop-x');

        $this->assertSame([BackupVerify::ENTRY_UNEXPECTED], $this->reasons($urteil));
    }

    public function test_an_archive_without_a_manifest_says_so(): void
    {
        $urteil = BackupVerify::verify($this->archive([], true), 'shop-x');

        $this->assertSame([BackupVerify::NO_MANIFEST], $this->reasons($urteil));

        // **Und es wird nicht weitergeprüft.** Ohne Verzeichnis weiss niemand,
        // was drin sein sollte; eine Liste von Prüfsummen daneben läse sich wie
        // ein halbes Urteil.
        $this->assertSame(0, $urteil['entries']);
    }

    public function test_a_manifest_from_a_newer_format_is_refused(): void
    {
        $urteil = BackupVerify::verify($this->archive([], false, Manifest::FORMAT + 1), 'shop-x');

        $this->assertSame([BackupVerify::NO_MANIFEST], $this->reasons($urteil));
        $this->assertStringContainsString('neueren Fassung', (string) $urteil['findings'][0]['detail']);
    }

    public function test_a_file_that_is_not_there_is_a_finding(): void
    {
        $urteil = BackupVerify::verify($this->scratch.'/gibtsnicht.zip', 'shop-x');

        $this->assertSame([BackupVerify::MISSING], $this->reasons($urteil));
        $this->assertSame(0, $urteil['bytes']);
    }

    public function test_something_that_is_not_an_archive_is_a_finding(): void
    {
        $pfad = $this->scratch.'/kaputt.zip';
        file_put_contents($pfad, 'das ist kein Zip');

        $urteil = BackupVerify::verify($pfad, 'shop-x');

        $this->assertSame([BackupVerify::UNREADABLE], $this->reasons($urteil));
    }

    /**
     * Ein abgeschnittenes Archiv — der Schaden, den schon `open()` sieht.
     *
     * Gemessen in `docs/117 §13` M8: `rc=19`. Der Fall steht hier, weil er
     * belegt, dass der frühe Ausstieg einen **Befund** erzeugt und keinen
     * Abbruch: Ein Vorgang, der wirft, liesse den Nachtlauf mit „nicht
     * nachgesehen" zurück statt mit „kaputt".
     */
    public function test_a_truncated_archive_is_a_finding_and_not_a_crash(): void
    {
        $pfad = $this->archive();
        $roh = (string) file_get_contents($pfad);
        file_put_contents($pfad, substr($roh, 0, strlen($roh) - 64));

        $urteil = BackupVerify::verify($pfad, 'shop-x');

        $this->assertSame([BackupVerify::UNREADABLE], $this->reasons($urteil));
        $this->assertFalse($urteil['healthy']);
    }

    /**
     * Jeder Grund, den diese Operation ausspricht, steht in ihrer eigenen
     * Aufzählung.
     *
     * **Gemessen an der Wirkung und nicht am Quelltext.** Die Fälle darüber
     * stellen fünf der sechs Gründe her; dieser hält, dass keiner davon an
     * `REASONS` vorbeikommt — sonst würfe `App\Enums\FindingCheck::state()`
     * nachts, in einem Lauf, den niemand sieht.
     */
    public function test_every_reason_it_speaks_stands_in_its_list(): void
    {
        $ausgesprochen = [];

        $urteile = [
            BackupVerify::verify($this->scratch.'/gibtsnicht.zip', 's'),
            BackupVerify::verify($this->archive([], true), 's'),
            BackupVerify::verify($this->archive([Manifest::DUMPS.'/shop.sql.gz']), 's'),
        ];

        foreach ($urteile as $urteil) {
            foreach ($urteil['findings'] as $finding) {
                $ausgesprochen[$finding['reason']] = true;
            }
        }

        $this->assertGreaterThanOrEqual(3, count($ausgesprochen), 'Es werden kaum Gründe hergestellt — dann prüft dieser Fall nichts.');

        foreach (array_keys($ausgesprochen) as $reason) {
            $this->assertContains($reason, BackupVerify::REASONS, sprintf(
                'Die Operation spricht %s aus, und ihre eigene Aufzählung kennt den Grund nicht.',
                $reason,
            ));
        }
    }

    /**
     * Und `mutating()` ist `false` — die Zusage von Entscheidung 1.
     *
     * Sie steht hier und nicht nur im Kopf der Klasse, weil sie eine Zusage an
     * den Betreiber ist: Der Prüflauf prüft und spielt nichts zurück.
     */
    public function test_it_changes_nothing(): void
    {
        $this->assertFalse(BackupVerify::mutating());
        $this->assertSame('backup.verify', BackupVerify::name());
    }
}
