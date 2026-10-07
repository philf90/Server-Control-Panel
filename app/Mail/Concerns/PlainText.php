<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

/**
 * Reiner Text, gebrochen unter 78 Zeichen — für die Mail an den Kunden und
 * jeden ihrer Abschnitte.
 *
 * **Eine Stelle und nicht vier.** Bis B9 stand das allein in der Kundenmail
 * über die Kontingente; seit Zertifikat und Sicherung dazugekommen sind
 * (`docs/142`), bricht jeder Abschnitt seine eigenen Zeilen, und zwei
 * Fassungen derselben Breite liefen beim nächsten Abschnitt auseinander.
 *
 * **Gebrochen wird nach Zeichen und nicht nach Bytes:** `wordwrap()` zählt
 * Bytes, und ein Umlaut ist zwei davon. Die Zeile vom 5. Oktober 2026 kam mit
 * dem Wert hinter dem Punkt auf 90 Zeichen (`docs/141 §0`).
 */
trait PlainText
{
    /** Wie lang eine Zeile höchstens wird — unter 78, damit kein Klient umbricht. */
    public const WIDTH = 76;

    /**
     * Einen Text an Wortgrenzen brechen, nach Zeichen gezählt.
     *
     * Ein Wort, das allein länger ist als die Zeile — ein langer
     * Abonnementname —, bleibt ganz: Mitten in einem Namen zu brechen, hiesse,
     * ihn unkenntlich zu machen.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $zeilen = [];
        $zeile = '';

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $wort) {
            if ($zeile === '') {
                $zeile = $wort;
            } elseif (mb_strlen($zeile.' '.$wort) <= $width) {
                $zeile .= ' '.$wort;
            } else {
                $zeilen[] = $zeile;
                $zeile = $wort;
            }
        }

        if ($zeile !== '') {
            $zeilen[] = $zeile;
        }

        return $zeilen;
    }

    /**
     * Eine Angabe unter einem Befund: eingerückt, mit Beschriftung, gebrochen.
     *
     * @return list<string>
     */
    public static function detailLines(string $beschriftung, string $wert): array
    {
        return array_map(
            static fn (string $teil): string => '  '.$teil,
            self::wrap($beschriftung.': '.$wert, self::WIDTH - 2),
        );
    }
}
