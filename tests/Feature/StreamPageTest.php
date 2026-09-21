<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutMarkupComments;
use Tests\TestCase;

/**
 * Nur die Vorgangsseite hält einen Ereigniskanal offen (B8, `docs/129 §8`).
 *
 * ## Die Begründung ist eine Zahl und keine Meinung
 *
 * Gemessen (`docs/128` M9): Der Panel-Pool hat **zwölf Arbeiter**, und **zwei
 * belegte lassen die nächste Anfrage 16 s warten**. Ein offener Strom hält
 * einen Arbeiter für seine ganze Laufzeit; `request_terminate_timeout` hilft
 * nicht, es steht auf einer Stunde.
 *
 * > **Zwölf offene Ströme machen das Panel für alle unerreichbar, und zwar für
 * > bis zu fünf Minuten.**
 *
 * B8 stand genau davor: Der Streifen der laufenden Vorgänge soll auf **jeder**
 * Seite stehen. Mit derselben Quelle wie die Vorgangsseite hiesse das, aus
 * einer von 58 Seiten alle 58 zu machen. Er fragt deshalb im Takt nach — und
 * dieser Wächter hält fest, dass niemand den bequemeren Weg zurücknimmt.
 *
 * ## Was er nicht hält
 *
 * Wie viele Reiter jemand offen hat. Zwei Reiter auf der Vorgangsseite sind
 * zwei Arbeiter, und dagegen hilft kein Test — das ist eine Frage an den Pool
 * und steht in `docs/132 §5` als Messung, die der Server beantwortet.
 */
final class StreamPageTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Wer eine `EventSource` bauen darf.
     *
     * **Ein Composable und eine Seite.** Der Composable ist die Umsetzung, die
     * Seite ihr einziger Aufrufer. Beide stehen hier, weil ein Wächter, der
     * nur die Seite nennt, den Composable freigäbe — und den ruft, wer will.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'resources/js/Composables/useOperationStream.ts',
        'resources/js/Pages/Operations/Show.vue',
    ];

    /** @return array<string, string> */
    private function frontend(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                $dateien[str_replace($wurzel.'/', '', $datei->getPathname())] =
                    (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }

    public function test_only_the_operation_page_opens_a_stream(): void
    {
        $funde = [];
        $gelesen = 0;

        foreach ($this->frontend() as $pfad => $quelle) {
            $gelesen++;

            if (in_array($pfad, self::ALLOWED, true)) {
                continue;
            }

            /*
             * **Kommentare fallen weg, bevor gesucht wird.** Der Kopf dieser
             * Datei — und der des Streifens — erklärt, warum dort *kein*
             * Strom steht, und schreibt das Wort dabei hin.
             *
             * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald
             * > sie irgendwo steht — und ein Kommentar, der die entfernte
             * > Zeile zitiert, stellt sie für ihn wieder her.**
             */
            $ohne = str_ends_with($pfad, '.vue')
                ? $this->withoutMarkupComments($quelle)
                : (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $quelle);

            if (str_contains($ohne, 'new EventSource') || str_contains($ohne, 'useOperationStream')) {
                $funde[] = $pfad;
            }
        }

        $this->assertGreaterThan(40, $gelesen,
            'Es werden kaum Dateien gelesen — dann prüft dieser Wächter nichts.');

        $this->assertSame([], $funde, sprintf(
            "Diese Vorlagen öffnen einen Ereigniskanal:\n  %s\n\n".
            "Ein offener Strom hält einen von zwölf FPM-Arbeitern; zwei belegte lassen die\n".
            'nächste Anfrage 16 s warten (docs/128 M9). Wer im Takt nachfragen kann, fragt nach.',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Und die Gegenrichtung: Die Vorgangsseite öffnet ihn noch.
     *
     * Ohne sie bestünde der Fall oben auch dann, wenn es gar keinen Strom mehr
     * gäbe — und niemand erführe, dass die Vorgangsseite ihre Ausgabe
     * verloren hat.
     */
    public function test_the_operation_page_still_opens_one(): void
    {
        $dateien = $this->frontend();

        foreach (self::ALLOWED as $pfad) {
            $this->assertArrayHasKey($pfad, $dateien, sprintf('%s gibt es nicht mehr.', $pfad));
        }

        $this->assertStringContainsString(
            'new EventSource',
            $dateien['resources/js/Composables/useOperationStream.ts'],
            'Der Composable baut keinen Ereigniskanal mehr — dann misst der Fall darüber nichts.',
        );

        $this->assertStringContainsString(
            'useOperationStream',
            $dateien['resources/js/Pages/Operations/Show.vue'],
            'Die Vorgangsseite ruft den Composable nicht mehr.',
        );
    }
}
