<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Hochladen legt an oder zerstört — und beides hiess gleich.
 *
 * ## Der Zustand, den dieser Wächter ablöst
 *
 * `FilesUpload` schreibt in eine Übergangsdatei und legt sie mit `rename()` an
 * ihren Platz. Das **überschreibt** einen vorhandenen Eintrag wortlos; eine
 * Prüfung auf `file_exists` gibt es an keiner Stelle, und das ist Absicht — der
 * Agent gibt stattdessen zurück, was er vorgefunden hat:
 *
 *     return ['entry' => Entry::of($path), 'created' => $existing === null];
 *
 * **Der Unterschied war also bekannt, und niemand trug ihn weiter.** Der
 * Controller warf das Ergebnis weg, die Meldung sagte in beiden Fällen „Die
 * Datei ist hochgeladen.", und das Protokoll schrieb `file.uploaded` mit dem
 * Pfad und sonst nichts (`docs/88`, Beobachtung 9).
 *
 * > **Eine Auskunft, die zwei verschiedene Vorgänge gleich benennt, verschweigt
 * > den gefährlicheren.**
 *
 * Ein Kunde, der `index.php` in sein `httpdocs` lädt, ersetzt die laufende
 * Datei seiner Website.
 *
 * ## Was hier gehalten wird
 *
 * 1. **Der Agent liefert `created`.** Ohne das Feld fällt der Controller auf
 *    „angelegt" zurück — die richtige Richtung, aber still. Diese Naht ist der
 *    Grund, warum der Rückfall im Controller kein Wächter sein kann.
 * 2. **Der Controller liest es**, zählt getrennt und schreibt `replaced` ins
 *    Protokoll.
 * 3. **Der Satz über das Ersetzen steht einmal.** Zwei Fassungen wären der
 *    Befund vom Vortag noch einmal.
 *
 * **Warum das Protokoll schwerer wiegt als die Meldung.** Die Meldung liest
 * jemand in dem Moment, in dem er ohnehin hinsieht. Das Protokoll liest jemand
 * Wochen später und fragt, wohin eine Datei verschwunden ist.
 */
final class UploadReplacementTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    private function source(string $relative): string
    {
        return $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /**
     * Der Agent sagt, ob er angelegt oder ersetzt hat.
     *
     * **Die eine Seite der Naht.** Fiele `created` aus der Antwort, läse der
     * Controller `null`, fiele auf „angelegt" zurück — und jede Ersetzung wäre
     * wieder unsichtbar, ohne dass irgendwo etwas rot würde.
     */
    public function test_the_agent_reports_whether_it_replaced_something(): void
    {
        $this->assertSame(1, preg_match(
            '/\x27created\x27 => \$existing === null/',
            $this->source('agent/src/Ops/FilesUpload.php'),
        ), 'files.upload sagt nicht mehr, ob es etwas ersetzt hat — dann fällt die Meldung still auf „angelegt" zurück.');
    }

    /**
     * Der Controller liest es und zählt getrennt.
     */
    public function test_the_controller_counts_replacements(): void
    {
        /*
         * **Gelesen wird der Rumpf von `upload()` und nicht die ganze Datei.**
         * Der erste Wurf suchte `$result['created']` im Ganzen und blieb beim
         * Bruch grün: Dieselbe Zeile steht in `create()`, seit P6 und zu Recht.
         * Der Wächter fand die fremde Stelle und war zufrieden.
         *
         * > **Ein Wächter, der eine Zeichenkette in einer ganzen Datei sucht,
         * > findet sie auch dort, wo sie einem anderen gehört.**
         */
        $rumpf = $this->methodBody(
            $this->source('app/Http/Controllers/FileController.php'),
            'public function upload(',
        );

        $this->assertSame(1, preg_match('/\$result\[\x27created\x27\]/', $rumpf),
            'Das Hochladen wertet `created` nicht aus — dann heisst Anlegen und Ersetzen wieder gleich.');

        $this->assertSame(1, preg_match('/\$replaced\+\+/', $rumpf),
            'Die Ersetzungen werden nicht gezählt.');
    }

    /**
     * Das Protokoll trägt den Unterschied.
     *
     * **Gelesen wird der Zusammenhang und nicht das blosse Wort.** Ein
     * `replaced` irgendwo in der Datei sagt nichts darüber, dass es am Eintrag
     * `file.uploaded` hängt — und genau dort wird es später gebraucht.
     */
    public function test_the_audit_entry_carries_the_difference(): void
    {
        $this->assertSame(1, preg_match(
            '/\x27file\.uploaded\x27.*?\x27replaced\x27 => /s',
            $this->source('app/Http/Controllers/FileController.php'),
        ), 'Der Protokolleintrag des Hochladens nennt nicht, ob er etwas ersetzt hat.');
    }

    /**
     * Der Satz über das Ersetzen steht einmal.
     *
     * **Dieselbe Regel wie bei `SystemPackagesRefresh` einen Tag vorher.** Dort
     * stand der Vorbehalt zweimal in derselben Datei und unterschied sich im
     * ersten Buchstaben; hier wird der Zusatz an zwei Stellen gebraucht — im
     * Erfolgssatz und im Fehlerzweig — und darf deshalb nur an einer entstehen.
     */
    public function test_the_wording_about_replacing_exists_once(): void
    {
        $quelle = $this->source('app/Http/Controllers/FileController.php');

        /*
         * **Gezählt wird der Ort und nicht die Zeichenkette.** Der erste Wurf
         * verlangte genau einen Treffer und war rot: Einzahl und Mehrzahl
         * stehen beide in `replacedNote()`, und das ist richtig so. Die Regel
         * lautet nicht „einmal im Text", sondern „an einer Stelle".
         *
         * > **Ein Wächter, der eine Zeichenkette zählt, zählt auch die
         * > Beugung mit.**
         */
        $rumpf = $this->methodBody($quelle, 'private static function replacedNote(');

        $imGanzen = preg_match_all('/vorhandene ersetzt/', $quelle);

        $this->assertGreaterThan(0, $imGanzen,
            'Der Satz über das Ersetzen steht nicht mehr da — dann prüft dieser Wächter nichts.');

        $this->assertSame($imGanzen, preg_match_all('/vorhandene ersetzt/', $rumpf),
            'Der Satz über das Ersetzen steht ausserhalb von replacedNote() — zwei Fassungen laufen auseinander.');

        $this->assertSame(2, preg_match_all('/self::replacedNote\(/', $quelle),
            'Der Zusatz wird nicht an beiden Stellen gebraucht — Erfolgssatz und Fehlerzweig sagen sonst Verschiedenes.');
    }
}
