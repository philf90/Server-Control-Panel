<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Databases\Dumps;

/**
 * Woher eine Sicherung stammt.
 *
 * **Der Unterschied entscheidet beim Zurückspielen.** Eine Sicherung, die
 * dieser Server selbst geschrieben hat, ist geprüft: Er kennt ihr Schema, ihre
 * Grösse und dass sie ohne `DEFINER`-Angabe entstand. Eine mitgebrachte hat
 * niemand angesehen — sie kann aus einer anderen MariaDB-Fassung kommen, aus
 * einer anderen Datenbank, oder mitten im Schreiben abgebrochen worden sein.
 * Zurückspielen leert die Datenbank *vorher*; wer die beiden nicht
 * unterscheiden kann, trifft diese Wahl blind. Deshalb trägt die mitgebrachte
 * in der Liste eine Marke.
 *
 * **Warum es diese Aufzählung erst seit dem 9. August gibt.** Bis dahin war
 * `kind` eine nackte Spalte, und ihre zwei Werte standen als Zeichenketten an
 * vier Stellen verstreut — zweimal in {@see Dumps},
 * einmal im Agenten, einmal im Vue-Template. Nebenan ist {@see DumpStatus}
 * längst eine Aufzählung. Aufgefallen ist es im Abnahmelauf von P5
 * (`docs/36 §22.3w`), an einer Kleinigkeit: Die Werte heissen `export` und
 * `imported` — ein Stamm und ein Partizip. Wer `'exported'` schreibt, trifft
 * nichts, und niemand sagt es ihm.
 *
 * **Die Werte bleiben trotzdem, wie sie sind, und das ist eine Entscheidung.**
 * Sie stehen in den Zeilen laufender Installationen; sie anzugleichen hiesse,
 * eine Datenmigration über Kundendaten zu fahren, um zwei Wörter grammatisch
 * zueinander passen zu lassen. Der Fehler, der drohte, war nie die Asymmetrie
 * — er war, dass man die Zeichenketten *tippen* musste. Ab hier tippt sie
 * niemand mehr, und ein Tippfehler ist ein Fehler beim Laden der Klasse.
 */
enum DumpKind: string
{
    /** Von diesem Panel geschrieben. */
    case Export = 'export';

    /** Hochgeladen (`docs/36 §15`, Schritt 11). */
    case Imported = 'imported';

    /**
     * Wie es in der Oberfläche heisst — oder `null`, wenn es nichts zu sagen gibt.
     *
     * **Nur die mitgebrachte trägt eine Marke.** Eine Marke an jeder Zeile wäre
     * keine Auskunft mehr: Der Regelfall braucht kein Etikett, und eine Spalte,
     * in der überall dasselbe steht, liest nach zwei Tagen niemand mehr.
     */
    public function label(): ?string
    {
        return match ($this) {
            self::Export => null,
            self::Imported => 'mitgebracht',
        };
    }
}
