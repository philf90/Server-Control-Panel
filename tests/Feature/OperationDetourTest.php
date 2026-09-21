<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;
use Tests\TestCase;

/**
 * Wer einen Vorgang absetzt, bleibt, wo er ist (B8, `docs/132`).
 *
 * ## Der Befund
 *
 * Gefunden vom Betreiber am 31. August 2026, **beim Erklären** und nicht beim
 * Prüfen: Die Frage lautete, wie man denselben Knopf ein zweites Mal drückt,
 * und die Antwort war „mit dem Zurück-Knopf des Browsers".
 *
 * > **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe
 * > nimmt, ist keiner, den die Anwendung anbietet.** (`docs/92 §1`)
 *
 * Gemessen waren es **22 Weiterleitungen aus acht Controllern** — `docs/92`
 * zählte 21 aus sieben; `BackupController` ist mit P8 dazugekommen.
 *
 * > **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts
 * > meldet es.**
 *
 * ## Die Ausnahme ist die Vorgangsseite selbst
 *
 * `OperationController` darf dorthin weiterleiten: Wer dort „noch einmal"
 * oder „abbrechen" drückt, will dort bleiben. Eine Regel ohne diese Ausnahme
 * wäre keine Regel, sondern ein Verbot — und sie stünde still, sobald jemand
 * sie für zu streng hält.
 *
 * ## Was er nicht hält
 *
 * Ob das Ziel das **richtige** ist. Dass `UpdatesController` nach `updates`
 * zurückführt und nicht nach `overview`, hängt daran, wo der Knopf steht — und
 * das ist keine Eigenschaft des Quelltextes. Was ein Test halten kann, ist:
 * **nicht mehr zur Vorgangsseite.**
 */
final class OperationDetourTest extends TestCase
{
    use WithoutPhpComments;

    /** Der eine Controller, der es darf — und warum, steht im Kopf. */
    private const ALLOWED = 'OperationController.php';

    /**
     * Wie viele Stellen mindestens einen Vorgang absetzen.
     *
     * Gemessen am 23. September 2026: **19** ausserhalb von
     * {@see self::ALLOWED} haben weitergeleitet, und jede davon setzt einen
     * Vorgang ab. Die Untergrenze steht deutlich darunter — sie soll nicht die
     * Zahl festschreiben, sondern den Fall abfangen, dass der Ausdruck ins
     * Leere greift.
     */
    private const AT_LEAST = 10;

    /** @return array<string, string> */
    private function controllers(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/app/Http/Controllers', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $dateien[str_replace($wurzel.'/', '', $datei->getPathname())] =
                    $this->withoutComments((string) file_get_contents($datei->getPathname()));
            }
        }

        ksort($dateien);

        return $dateien;
    }

    public function test_no_controller_carries_the_viewer_to_the_operation_page(): void
    {
        $funde = [];

        foreach ($this->controllers() as $pfad => $quelle) {
            if (str_ends_with($pfad, self::ALLOWED)) {
                continue;
            }

            $treffer = preg_match_all("/(?:to_route|->\s*route)\(\s*'operations\.show'/", $quelle);

            if ($treffer > 0) {
                $funde[] = sprintf('%s: %d×', $pfad, $treffer);
            }
        }

        $this->assertSame([], $funde, sprintf(
            "Diese Controller tragen ihren Betrachter auf die Vorgangsseite:\n  %s\n\n".
            "Wer einen Knopf drückt, bleibt, wo er ist; den Fortschritt trägt der Streifen oben.\n".
            'Der Weg zur Vorgangsseite steht dort als „ansehen".',
            implode("\n  ", $funde),
        ));
    }

    /**
     * Und die Gegenrichtung: Es gibt noch Stellen, die einen Vorgang absetzen.
     *
     * Ohne sie stünde der Fall oben auch dann grün da, wenn niemand mehr einen
     * Vorgang anlegt — oder wenn der Ausdruck seine Schreibweise nicht mehr
     * trifft.
     *
     * > **Eine Untergrenze ist kein Formalismus — sie ist die einzige Stelle,
     * > an der ein Wächter merkt, dass sein Ausdruck ins Leere greift.**
     */
    public function test_there_are_still_places_that_dispatch(): void
    {
        $stellen = 0;

        foreach ($this->controllers() as $pfad => $quelle) {
            if (str_ends_with($pfad, self::ALLOWED)) {
                continue;
            }

            $stellen += preg_match_all('/\$operation\b|->start\(/', $quelle) > 0 ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(self::AT_LEAST, $stellen * 3, sprintf(
            'Nur %d Controller setzen noch Vorgänge ab — dann prüft der Fall darüber nichts.',
            $stellen,
        ));
    }
}
