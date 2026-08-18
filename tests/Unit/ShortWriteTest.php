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
 * geschrieben hat, was noch passte. Was der PHP-Aufruf daraus macht, hängt vom
 * Aufruf ab, und **das war hier bis zum 18. August 2026 falsch aufgeschrieben:**
 *
 * | Aufruf | bei einem kurzen Schreibvorgang |
 * |---|---|
 * | `file_put_contents` | **`false`** — PHP warnt „Only X of Y bytes written" und wirft die Zahl weg |
 * | `stream_copy_to_stream` | die Zahl der kopierten Bytes |
 *
 * Gemessen auf `cloudsrv24` mit `tests/quota-messen.php`; hier stand vorher für
 * beide „die Zahl". Der Vergleich gegen die erwartete Länge fängt trotzdem
 * beide Fälle: `false !== 2097152` ist ebenso wahr wie `1048576 !== 2097152`.
 * Wer dagegen nur auf `false` prüft, meldet dem Kunden „gespeichert" für eine
 * Datei, von der die Hälfte fehlt — und die Hälfte ist hier eine halbe
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
 *
 * **Und dieser Wächter war am 18. August selbst grün, während die Meldung nicht
 * ankam.** Er suchte den Satz „Kontingent erschöpft" im Quelltext — und der
 * stand dort, in einem von **zwei** Zweigen. Gemessen auf `cloudsrv24`: PHPs
 * `file_put_contents` gibt bei voller Quota `false` zurück und nicht die Zahl
 * der geschriebenen Bytes, also lief immer der andere Zweig, und der Kunde las
 * „Die Datei liess sich nicht schreiben". Der Satz war da und unerreichbar.
 *
 * > **Ein Wächter, der einen Satz sucht statt seiner Erreichbarkeit, ist grün,
 * > sobald der Satz irgendwo steht.**
 *
 * Deshalb prüft er seitdem, dass es für einen kurzen Schreibvorgang **eine**
 * Meldung gibt und nicht zwei. Zwei Zweige für denselben Fall laufen
 * auseinander, und die falsche ist die, die man bekommt.
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
     * **Und `Files\Packer` steht bewusst nicht dabei.** Er schreibt auch, aber
     * seine Frage lautet anders: `ZipArchive::close()` gibt einen echten
     * Wahrheitswert zurück, es gibt dort keine Zahl, die kurz sein könnte. Die
     * anderen drei Regeln erfüllt er (eine Meldung, sie nennt das Kontingent,
     * der Rest fliegt vor dem Wurf) — wer ihn hier einträgt, sucht eine Zeile,
     * die es aus gutem Grund nicht gibt.
     *
     * **Der Lieferant gibt genau einen Wert je Fall**, und der Vergleichsausdruck
     * steht daneben in {@see expected()}. Der erste Entwurf lieferte beide und
     * liess drei der fünf Methoden nur den ersten entgegennehmen — PHPUnit
     * meldet dafür je Methode eine Warnung, und der Lauf endet mit
     * Rückgabewert 1, obwohl keine Behauptung gebrochen ist. Gefunden hat es
     * die CI, nicht das Gestell im Container: Es reichte die überzähligen Werte
     * wortlos weiter.
     *
     * > **Eine Attrappe, die weniger verbietet als das Original, sagt Ja zu
     * > Code, den das Original ablehnt.**
     *
     * @return array<string, array{string}>
     */
    public static function operations(): array
    {
        return [
            'files.write' => ['FilesWrite.php'],
            'files.upload' => ['FilesUpload.php'],
        ];
    }

    /** Womit die geschriebene Länge in dieser Operation verglichen wird. */
    private function expected(string $datei): string
    {
        return match ($datei) {
            'FilesWrite.php' => 'strlen($content)',
            'FilesUpload.php' => '$size',
            default => throw new \LogicException('Unbekannte Operation: '.$datei),
        };
    }

    /**
     * Verglichen wird mit der erwarteten Länge — nicht mit `false`.
     */
    #[DataProvider('operations')]
    public function test_a_short_write_is_a_failure(string $datei): void
    {
        $quelltext = $this->source($datei);
        $erwartet = $this->expected($datei);

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
    public function test_the_half_written_file_is_removed(string $datei): void
    {
        $quelltext = $this->source($datei);
        $vergleich = strpos($quelltext, '$written !== '.$this->expected($datei));

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

    /**
     * Für einen kurzen Schreibvorgang gibt es **eine** Meldung.
     *
     * **Das ist der Fall, den es vorher nicht gab, und er hätte den Fehler
     * gefunden.** Steht die Meldung in einem Bedingungsausdruck, hängt sie an
     * einer Annahme über den Rückgabewert — und wenn die Annahme falsch ist,
     * bekommt der Kunde den Zweig, den niemand gemeint hat. Der erste Ausdruck
     * von `execFailed` muss deshalb unmittelbar eine Zeichenkette sein.
     */
    #[DataProvider('operations')]
    public function test_there_is_one_message_and_not_two(string $datei): void
    {
        $this->assertMatchesRegularExpression(
            "/execFailed\(\s*'/",
            $this->source($datei),
            implode("\n", [
                sprintf('In %s haengt die Meldung an einer Bedingung.', $datei),
                'Zwei Meldungen fuer denselben Fall laufen auseinander — und die',
                'falsche ist die, die man bekommt (gemessen am 18. August 2026).',
            ]),
        );
    }

    /**
     * Eine unbekannte Zahl heisst `null` und nicht `0`.
     *
     * Bei `false` weiss dieser Weg nicht, wie viel angekommen ist — PHP kennt
     * die Zahl und gibt sie nicht heraus. Eine `0` im Protokoll behauptete
     * „nichts geschrieben", und das ist eine Auskunft, die niemand hat.
     * Dieselbe Form wie überall sonst hier: `null` heisst „nicht gemessen".
     */
    #[DataProvider('operations')]
    public function test_an_unknown_count_is_null(string $datei): void
    {
        $this->assertStringContainsString(
            '$written === false ? null : $written',
            $this->source($datei),
            sprintf('%s meldet eine 0, wo es die Zahl nicht kennt.', $datei),
        );
    }

    private function source(string $datei): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/'.$datei),
        );
    }
}
