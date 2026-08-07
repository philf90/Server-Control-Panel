<?php

declare(strict_types=1);

namespace Tests\Support;

use ReflectionMethod;

/**
 * Der Rumpf einer Methode als Text — für Wächter, die im Code lesen.
 *
 * **Warum die Methode und nicht die Datei.** Ein `withTrashed()` irgendwo sonst
 * im Controller beantwortet die Frage nicht, ob *dieser* Löschweg die
 * Grabsteine mitzählt. Genauso wenig sagt ein `SystemUser::` im Konstruktor,
 * dass die Vergabe das Verzeichnis fragt.
 *
 * Herausgelöst aus `RestrictedDeleteTest::destroySource()`, als der zweite
 * Wächter dieselbe Frage stellte (docs/35 §5.2). Zwei Fassungen davon hiessen:
 * zwei Stellen, an denen dieselbe Reflexion steht, und die eine, die beim
 * nächsten Mal nachgezogen wird, ist erfahrungsgemäss nicht beide.
 *
 * **Der Dokumentationsblock steht mit drin und ist Absicht.** `getStartLine()`
 * zeigt auf die Zeile mit den Modifizierern, nicht auf den Block darüber — was
 * hier zurückkommt, ist der Code und nicht der Kommentar. Wer in einem Wächter
 * nach einem Klassennamen sucht, sucht ihn also im Code; ein Klassenname, der
 * nur in der Erklärung darüber steht, löst nichts aus.
 */
trait ReadsMethodSource
{
    /**
     * @param  class-string  $class
     */
    protected function methodSource(string $class, string $method): ?string
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false) {
            return null;
        }

        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
