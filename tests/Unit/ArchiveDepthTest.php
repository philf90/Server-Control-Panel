<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Files\Archive;
use ZipArchive;

/**
 * Ein Archiv wird vollständig aufgezählt — bis in jede Ebene.
 *
 * **Dieser Wächter entstand aus einem Fehler, den kein Test finden konnte, weil
 * keiner ein Archiv gebaut hat.** `Archive::names()` zählte ein Tar mit
 * `foreach (new PharData($archive) as $file)` auf, und diese Schleife läuft über
 * die **oberste Ebene**. Ein gewöhnliches Archiv mit `oben.txt`,
 * `dir/mitte.txt` und `dir/sub/tief.txt` ergab damit zwei Namen statt fünf:
 * `oben.txt` wurde geschrieben, `dir` landete unter „verlegt", und die beiden
 * Dateien darunter verschwanden spurlos.
 *
 * Das ist kein Ausbruch — es ist ein Merkmal, das für jedes Tar mit einem
 * Unterverzeichnis das Falsche tut. Gefunden hat es der Bau der Punkte 7 und 8
 * des Angriffsdurchgangs (`docs/62`), also ein Prüfstand für eine ganz andere
 * Frage. Zip war nie betroffen: `ZipArchive` zählt über den **Index** auf und
 * kennt keine Ebenen.
 *
 * > **Eine Aufzählung, die Ebenen hat, zählt nicht dasselbe wie eine, die keine
 * > hat.**
 *
 * **Die Archive entstehen hier von Hand**, Byte für Byte, und nicht über ein
 * Programm des Systems. Zwei Gründe: `PharData` kann keinen Eintrag schreiben,
 * der mit `..` beginnt — genau den braucht der letzte Fall —, und ein Archiv aus
 * dem Prüfling selbst prüfte den Prüfling gegen sich.
 */
final class ArchiveDepthTest extends TestCase
{
    private string $scratch = '';

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/archive-depth-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/ziel', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->scratch));
    }

    /**
     * Ein Tar mit Unterverzeichnissen kommt vollständig an.
     */
    public function test_a_nested_tar_arrives_completely(): void
    {
        $archive = $this->scratch.'/tief.tar';
        $this->tar($archive, [
            'oben.txt' => "eins\n",
            'dir/' => null,
            'dir/mitte.txt' => "zwei\n",
            'dir/sub/' => null,
            'dir/sub/tief.txt' => "drei\n",
        ]);

        $result = Archive::extract($archive, $this->scratch.'/ziel');

        $this->assertSame(5, $result['entries'], 'Ein Tar mit Unterverzeichnissen wird nicht vollständig aufgezählt.');
        $this->assertSame(0, $result['unnamed'], 'Das Archiv hat Einträge, die sich nicht benennen lassen — es hat keine.');
        $this->assertSame([], $result['redirected'], 'Ein Verzeichnis ist kein verlegter Eintrag.');

        foreach (['oben.txt' => 'eins', 'dir/mitte.txt' => 'zwei', 'dir/sub/tief.txt' => 'drei'] as $pfad => $inhalt) {
            $this->assertSame(
                $inhalt,
                trim((string) @file_get_contents($this->scratch.'/ziel/'.$pfad)),
                sprintf('„%s" ist nicht angekommen.', $pfad),
            );
        }
    }

    /**
     * **Dieselbe Regel für Zip** — und nicht, weil Zip je gebrochen war.
     *
     * Der Fehler steckte in einer von zwei Aufzählungen. Ein Wächter, der nur
     * die kaputte prüft, sagt über das Merkmal nichts aus, sondern nur über
     * seinen Anlass — und die nächste Umstellung trifft dann die andere Hälfte.
     */
    public function test_a_nested_zip_arrives_completely(): void
    {
        $archive = $this->scratch.'/tief.zip';
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE);
        $zip->addFromString('oben.txt', "eins\n");
        $zip->addEmptyDir('dir/sub');
        $zip->addFromString('dir/mitte.txt', "zwei\n");
        $zip->addFromString('dir/sub/tief.txt', "drei\n");
        $zip->close();

        $result = Archive::extract($archive, $this->scratch.'/ziel');

        $this->assertSame(0, $result['unnamed'], 'Ein Zip hat keine Einträge ohne Namen.');

        foreach (['oben.txt' => 'eins', 'dir/mitte.txt' => 'zwei', 'dir/sub/tief.txt' => 'drei'] as $pfad => $inhalt) {
            $this->assertSame(
                $inhalt,
                trim((string) @file_get_contents($this->scratch.'/ziel/'.$pfad)),
                sprintf('„%s" ist nicht angekommen.', $pfad),
            );
        }
    }

    /**
     * Ein Eintrag, der sich nicht benennen lässt, wird gezählt.
     *
     * **`PharData` zählt ihn und zeigt ihn nicht.** `count($phar)` ist 2,
     * aufzählen lässt sich einer. Vor dieser Zeile meldete der Vorgang für ein
     * solches Archiv „1 Eintrag, 1 geschrieben, 0 übersprungen" — also, dem
     * Kunden gegenüber, dass nichts fehle.
     *
     * > **Eine Zahl ohne Namen ist eine magere Auskunft. Keine Auskunft ist die
     * > Behauptung, es fehle nichts.**
     */
    public function test_an_entry_that_cannot_be_named_is_counted(): void
    {
        $archive = $this->scratch.'/boes.tar';
        $this->tar($archive, [
            '../../../../'.ltrim($this->scratch, '/').'/getroffen' => "peng\n",
            'beweis' => "brav\n",
        ]);

        $result = Archive::extract($archive, $this->scratch.'/ziel');

        $this->assertSame(1, $result['unnamed'], 'Der Eintrag, der hinausführt, wird verschwiegen.');
        $this->assertFileDoesNotExist($this->scratch.'/getroffen', 'Der Eintrag ist ausserhalb des Ziels gelandet.');
        $this->assertFileExists($this->scratch.'/ziel/beweis', 'Das Archiv wurde gar nicht entpackt.');
    }

    /**
     * **Die Gegenprobe zum vorigen Fall.**
     *
     * `unnamed = 1` und nichts ausserhalb sähe genauso aus, wenn der Eintrag im
     * Archiv gar nicht stünde oder wenn das Archiv unlesbar wäre. Dasselbe
     * Archiv ohne das `..` davor muss seine Nutzlast abliefern — sonst misst
     * der Fall darüber nichts.
     *
     * > **Eine Gegenprobe, die nicht treffen kann, ist keine.**
     */
    public function test_the_same_payload_arrives_without_the_way_out(): void
    {
        $archive = $this->scratch.'/brav.tar';
        $this->tar($archive, ['getroffen' => "peng\n", 'beweis' => "brav\n"]);

        $result = Archive::extract($archive, $this->scratch.'/ziel');

        $this->assertSame(0, $result['unnamed'], 'Ein harmloses Archiv hat keine Einträge ohne Namen.');
        $this->assertSame('peng', trim((string) @file_get_contents($this->scratch.'/ziel/getroffen')));
    }

    /**
     * Ein Tar von Hand — 512 Byte Kopf je Eintrag, dann der Inhalt.
     *
     * @param  array<string, string|null>  $entries  Inhalt, oder `null` für ein Verzeichnis
     */
    private function tar(string $path, array $entries): void
    {
        $out = '';

        foreach ($entries as $name => $content) {
            $directory = $content === null;
            $size = $directory ? 0 : strlen((string) $content);

            $header = str_pad((string) $name, 100, "\0")
                .str_pad($directory ? '0000755' : '0000644', 8, "\0")
                .str_pad('0000000', 8, "\0")
                .str_pad('0000000', 8, "\0")
                .sprintf('%011o', $size)."\0"
                .sprintf('%011o', 0)."\0"
                // Die Prüfsumme wird über den Kopf gerechnet, in dem an ihrer
                // eigenen Stelle acht Leerzeichen stehen — so steht es in
                // tar(5), und ohne diesen Umweg wäre sie von sich selbst
                // abhängig.
                .'        '
                .($directory ? '5' : '0')
                .str_repeat("\0", 100)
                ."ustar\0".'00'
                .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

            $sum = 0;

            for ($i = 0; $i < 512; $i++) {
                $sum += ord($header[$i]);
            }

            $out .= substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);

            if (! $directory) {
                $out .= str_pad((string) $content, (int) ceil($size / 512) * 512, "\0");
            }
        }

        // Zwei leere Blöcke schliessen ein Tar ab.
        file_put_contents($path, $out.str_repeat("\0", 1024));
    }
}
