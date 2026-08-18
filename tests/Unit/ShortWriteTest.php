<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Ein kurzer Schreibvorgang ist kein erfolgreicher.
 *
 * **Das ist die Regel, an der Punkt 12 des Abnahmekriteriums hängt** (volle
 * Quota, `docs/51 §4`), und bis zum 18. August 2026 hat sie nichts geschützt.
 *
 * Bei erschöpftem Kontingent gibt der Kernel `EDQUOT` — aber erst, nachdem er
 * geschrieben hat, was noch passte. `file_put_contents` und
 * `stream_copy_to_stream` melden dann die **Zahl der geschriebenen Bytes** und
 * nicht `false`. Wer nur auf `false` prüft, meldet dem Kunden „gespeichert" für
 * eine Datei, von der die Hälfte fehlt — und die Hälfte ist hier eine halbe
 * `wp-config.php`.
 *
 * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
 * > (`docs/48`)
 *
 * **Warum ein Wächter über den Quelltext und kein Lauf.** Eine volle Quota
 * herzustellen braucht ein Dateisystem mit `usrquota`, ein gelaufenes
 * `quotacheck` und root — nichts davon gibt es in der CI. Gemessen wird das auf
 * einem echten Server mit `tests/quota-messen.php`; hier wird festgehalten,
 * dass die Zeile, die es misst, nicht wegvereinfacht wird.
 *
 * Der Vergleich ist die eine Stelle, an der ein „schlanker" Umbau lautlos
 * Schaden anrichtet: `$written === false` liest sich harmloser als
 * `$written !== $size` und ist in neun von zehn Fällen dasselbe. Der zehnte ist
 * der, um den es geht.
 */
final class ShortWriteTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Die beiden Wege, auf denen Inhalt eines Kunden auf die Platte kommt.
     *
     * **Beide, und nicht nur einer.** `files.write` ist der Editor,
     * `files.upload` das Hochladen — dieselbe Regel, zwei Stellen. Ein Wächter,
     * der nur die eine prüft, sagt über das Merkmal nichts aus, sondern nur
     * über seinen Anlass.
     *
     * @return array<string, array{string, string}>
     */
    public static function operations(): array
    {
        return [
            'files.write' => ['FilesWrite.php', 'strlen($content)'],
            'files.upload' => ['FilesUpload.php', '$size'],
        ];
    }

    /**
     * Verglichen wird mit der erwarteten Länge — nicht mit `false`.
     */
    #[DataProvider('operations')]
    public function test_a_short_write_is_a_failure(string $datei, string $erwartet): void
    {
        $quelltext = $this->source($datei);

        $this->assertStringContainsString(
            '$written !== '.$erwartet,
            $quelltext,
            implode("\n", [
                sprintf('%s vergleicht nicht gegen die erwartete Laenge.', $datei),
                'Bei voller Quota meldet der Aufruf die Zahl der geschriebenen',
                'Bytes und nicht false — ohne diesen Vergleich heisst',
                '„Kontingent erschoepft" gegenueber dem Kunden „gespeichert".',
            ]),
        );

        $this->assertDoesNotMatchRegularExpression(
            '/if \(\$written === false\)/',
            $quelltext,
            sprintf('%s prueft nur auf false — das ist der Fall, der nicht eintritt.', $datei),
        );
    }

    /**
     * Und die Begründung nennt das Kontingent.
     *
     * „Die Datei liess sich nicht schreiben" klingt nach einem Defekt des
     * Servers; der Kunde meldet einen Ausfall, wo er Platz schaffen müsste.
     * `docs/19 §6`: Eine Meldung sagt, was zu tun ist.
     */
    #[DataProvider('operations')]
    public function test_the_reason_names_the_allowance(string $datei): void
    {
        $this->assertStringContainsString(
            'Kontingent erschöpft',
            $this->source($datei),
            sprintf('%s meldet den Fehlschlag ohne den Grund, den der Kunde beheben kann.', $datei),
        );
    }

    /**
     * Der halb geschriebene Rest wird weggeräumt, bevor die Ausnahme fliegt.
     *
     * **Sonst frisst jeder Fehlversuch dauerhaft am Kontingent** — und zwar
     * unter einem Namen, der mit einem Punkt beginnt und in keiner Auflistung
     * auftaucht. Der Kunde sähe ein volles Kontingent und keine Dateien.
     */
    #[DataProvider('operations')]
    public function test_the_half_written_file_is_removed(string $datei, string $erwartet): void
    {
        $quelltext = $this->source($datei);
        $vergleich = strpos($quelltext, '$written !== '.$erwartet);

        $this->assertIsInt($vergleich, sprintf('In %s gibt es den Vergleich nicht mehr.', $datei));

        $wurf = strpos($quelltext, 'AgentException::execFailed', (int) $vergleich);
        $weg = strpos($quelltext, 'unlink($temporary)', (int) $vergleich);

        $this->assertIsInt($weg, sprintf('%s raeumt den Rest nach einem kurzen Schreibvorgang nicht weg.', $datei));
        $this->assertIsInt($wurf, sprintf('%s meldet den kurzen Schreibvorgang nicht als Fehlschlag.', $datei));

        $this->assertLessThan(
            (int) $wurf,
            (int) $weg,
            sprintf('%s wirft, bevor es den Rest wegraeumt — die Zeile danach laeuft nie.', $datei),
        );
    }

    private function source(string $datei): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/'.$datei),
        );
    }
}
