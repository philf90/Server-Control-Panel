<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Files\Sftp;
use PHPUnit\Framework\TestCase;

/**
 * Was der Agent zum Neuladen sagt, liest der Kunde.
 *
 * **Der Fund** (`docs/59`, Befund 21). `SftpAccess::reload()` baut den Satz
 * „ssh.service ist inactive — die neue Datei gilt ab der nächsten Verbindung",
 * `Sftp::sync()` gibt ihn zurück — und `add()` und `remove()` warfen den
 * Rückgabewert weg. `docs/58` Punkt 9 verlangt diesen Satz ausdrücklich als eine
 * der vier Zeilen.
 *
 * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
 * > keine.**
 *
 * Dieselbe Form wie Befund 13, nur eine Ebene weiter: Dort fehlte der Träger
 * zwischen Controller und Seite, hier zwischen Agent und Controller.
 *
 * ## Zwei Regeln, und die erste ist die, die hält
 *
 * Die Textprüfung unten liest den Quelltext und läuft ohne Framework — sie ist
 * die, die beim nächsten Umbau zubeisst. Die beiden Verhaltensprüfungen brauchen
 * den Autoloader der Anwendung und laufen nur in der CI.
 */
final class SftpNoteTest extends TestCase
{
    private function source(string $relative): string
    {
        // Kommentare weg: Die Begründung nennt beide Namen, und ein Wächter, der
        // Text liest, liesse sich von ihr überzeugen.
        return (string) preg_replace(
            ['#/\*.*?\*/#su', '#//[^\n]*#'],
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative),
        );
    }

    /** Der Rückgabewert von `sync()` wird nicht weggeworfen — und die Meldung trägt ihn. */
    public function test_the_answer_is_carried_from_the_agent_to_the_page(): void
    {
        $support = $this->source('app/Support/Files/Sftp.php');
        $controller = $this->source('app/Http/Controllers/SftpController.php');

        $verworfen = preg_match_all('/^\s*\$this->sync\(\);\s*$/m', $support);

        $this->assertSame(
            0,
            $verworfen,
            'In Sftp.php wird der Rückgabewert von sync() weggeworfen. Dann entsteht der Satz über '
            .'den ruhenden Dienst und erreicht niemanden.',
        );

        $this->assertStringContainsString(
            'spokenNote($this->sync())',
            $support,
            'sync() wird nicht durch spokenNote() gelesen.',
        );

        $this->assertSame(
            2,
            preg_match_all("/self::spoken\('Der Schlüssel ist/u", $controller),
            'Nicht beide Erfolgsmeldungen des Controllers tragen die Auskunft des Agenten weiter — '
            .'eingetragen und entfernt sind zwei Wege, und beide brauchen sie.',
        );
    }

    /**
     * Und nur der eine der drei Fälle ist eine Auskunft.
     *
     * „neu geladen" ist das Erwartete und sagt nichts; „nichts zu ändern"
     * beschreibt einen Vorgang, den der Kunde nicht angefordert hat.
     */
    public function test_only_a_resting_service_is_worth_a_sentence(): void
    {
        $satz = 'ssh.service ist inactive — die neue Datei gilt ab der nächsten Verbindung';

        $this->assertSame($satz, Sftp::spokenNote([
            'reload' => ['reloaded' => false, 'unit' => 'ssh.service', 'note' => $satz],
        ]));

        $this->assertNull(Sftp::spokenNote([
            'reload' => ['reloaded' => true, 'unit' => 'ssh.service', 'note' => 'neu geladen'],
        ]));

        $this->assertNull(Sftp::spokenNote([
            'reload' => ['reloaded' => false, 'unit' => null, 'note' => 'nichts zu ändern'],
        ]));
    }

    /** Eine Antwort ohne `reload` sagt nichts, statt zu raten. */
    public function test_a_missing_answer_says_nothing(): void
    {
        $this->assertNull(Sftp::spokenNote([]));
        $this->assertNull(Sftp::spokenNote(['reload' => 'kaputt']));
    }
}
