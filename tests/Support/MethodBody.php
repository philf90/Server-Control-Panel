<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Den Rumpf einer Methode aus PHP-Quelltext schneiden — über Klammern.
 *
 * **Nicht über einen Ausdruck bis zum nächsten `}`.** Genau daran ist der
 * dritte Bruch von `DispatchedRunTest` am 28. August vorbeigelaufen: Ein `.*?`
 * hinter der öffnenden Klammer läuft über das Ende der Methode hinaus bis zum
 * nächsten Treffer, und der Wächter prüfte fremden Code.
 *
 * > **Ein Wächter, der Wörter liest, sieht keine Klammern.**
 *
 * **Er steht hier und nicht zweimal in zwei Wächtern.** Am 30. August brauchte
 * ihn der zweite — und eine Kopie wäre die zweite Fassung eines Werkzeugs
 * gewesen, mit der bekannten Folge, dass beim nächsten Umbau nur eine
 * mitgeht.
 *
 * **Der Aufrufer streift die Kommentare vorher ab** ({@see WithoutPhpComments}).
 * Eine Klammer in einem Kommentar zählt sonst mit, und ein deutscher Satz mit
 * einer geschweiften Klammer ist selten, aber nicht unmöglich.
 */
trait MethodBody
{
    /**
     * Alles zwischen der öffnenden und der zugehörigen schliessenden Klammer.
     *
     * `$signature` ist der Anfang der Deklaration, etwa
     * `private static function replacedNote(`.
     */
    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);

        $this->assertNotFalse($start, sprintf('%s steht nicht im Quelltext.', $signature));

        $open = strpos($source, '{', $start);

        $this->assertNotFalse($open, sprintf('%s hat keinen Rumpf.', $signature));

        $tiefe = 0;

        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $tiefe++;
            } elseif ($source[$i] === '}') {
                $tiefe--;

                if ($tiefe === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        $this->fail(sprintf('Der Rumpf von %s endet nicht.', $signature));
    }
}
