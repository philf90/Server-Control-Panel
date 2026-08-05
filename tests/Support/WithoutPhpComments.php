<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP-Quelltext ohne seine Kommentare — für Wächter, die im Code lesen.
 *
 * **Warum das ein eigener Baustein ist und keine Zeile je Test.** Drei Wächter
 * hatten dieselbe Zeile abgeschrieben: ein `preg_replace` mit zwei Mustern,
 * das erste für Blockkommentare, das zweite `#//[^\n]*#` für Zeilenkommentare.
 *
 * Und das zweite ist falsch. `//` beginnt nicht nur einen Kommentar, es steht
 * auch in jeder URL. Aus
 *
 *     'APP_URL' => 'https://'.php_uname('n').':'.$port,
 *
 * wird `'APP_URL' => 'https:` — der Rest der Zeile gilt als Kommentar und
 * verschwindet. Ein Wächter, der auf diesem Text sucht, findet dort nichts
 * mehr.
 *
 * **Aufgefallen ist es nur beim Gegenprüfen.** `HostnameSourceTest` hat den
 * Bruch — genau diese Zeile mit `php_uname('n')` — nicht gemeldet: Der Aufruf
 * stand hinter einem `https://` und war für den Wächter nicht mehr da. Der
 * Test war grün, die Regel gebrochen. Derselbe Fall wie beim Bruchskript, dem
 * sein `sed` ins Leere lief: *Ein Werkzeug, das die Wächter trägt, braucht
 * selbst einen.*
 *
 * `token_get_all()` beantwortet die Frage exakt — der Parser weiss, was
 * Zeichenkette ist und was Kommentar. Ein regulärer Ausdruck weiss es nie.
 */
trait WithoutPhpComments
{
    private function withoutComments(string $php): string
    {
        $out = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                // Kommentare fallen weg, ihre Zeilenumbrüche bleiben: Sonst
                // rutschen die Zeilennummern, und eine Fundstelle liesse sich
                // nicht mehr zuordnen.
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
