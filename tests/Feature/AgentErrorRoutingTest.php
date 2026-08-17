<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Ein Fehler des Servers wird nicht an ein Eingabefeld geschrieben.
 *
 * **Der Fund** (`docs/59`, Befund 11, Phase B von Punkt 8): Bei einer kaputten
 * `sshd_config` brach das Eintragen eines Schlüssels richtig ab — und die
 * Meldung landete am Schlüsselfeld. Das Feld wurde rot, obwohl der Schlüssel
 * einwandfrei war; `PublicKey::parse()` hatte ihn eine Zeile vorher gelesen.
 *
 * > **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
 * > Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
 * > ist.**
 *
 * `AgentException` trägt den Grund mit: `badRequest` kommt aus der Prüfung der
 * Eingabe, `exec_failed`, `timeout` und `internal` sind Zustände des Servers.
 * Wer alle vier gleich behandelt, hat die Auskunft, die er braucht, und benutzt
 * sie nicht.
 *
 * ## Warum dieser Wächter nur einen Ort prüft
 *
 * **Dieselbe Form steht an vier weiteren Stellen** — `DatabaseController`
 * (`cidr` zweimal, `host`) und `FileController` (`path`). Sie gehören zu P5b und
 * zum Dateimanager, haben ihre eigenen Abnahmeläufe, und keine davon ist im
 * Lauf des SFTP-Zugangs gemessen worden.
 *
 * > **Ein Fehler, den man an fünf Stellen gleichzeitig behebt, ist an vier davon
 * > ungemessen behoben.**
 *
 * Der allgemeine Wächter kommt, wenn die vier anderen gemessen sind; bis dahin
 * stehen sie in `docs/59` als offene Arbeit, mit Namen. Ein Wächter, der heute
 * alle fünf verlangt, wäre rot — und ein roter Wächter wird abgeschaltet.
 */
final class AgentErrorRoutingTest extends TestCase
{
    private const FILE = 'app/Http/Controllers/SftpController.php';

    public function test_only_a_rejected_input_becomes_a_field_error(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::FILE);

        preg_match_all(
            '/catch \(AgentException[^)]*\)\s*\{(.*?)\n        \}/s',
            $source,
            $blocks,
            PREG_SET_ORDER,
        );

        $checked = 0;
        $broken = [];

        foreach ($blocks as $block) {
            /*
             * **Ohne Kommentare, und das ist kein Detail.** Die Begründung für
             * diese Verzweigung nennt die Marke, gegen die hier geprüft wird —
             * ein Wächter, der den Quelltext als Text liest, liesse sich von der
             * eigenen Begründung überzeugen. `FieldErrorTest` ist genau in diese
             * Falle gelaufen, nur in die andere Richtung.
             *
             * > **Ein Wächter, der Text liest, liest auch die Begründung dafür,
             * > warum er recht hat.**
             */
            $body = (string) preg_replace(['#/\*.*?\*/#su', '#//[^\n]*#'], '', $block[1]);

            if (! str_contains($body, 'withMessages')) {
                continue;
            }

            $checked++;

            if (! str_contains($body, 'AgentException::BAD_REQUEST')) {
                $broken[] = trim((string) preg_replace('/\s+/', ' ', $body));
            }
        }

        /*
         * Die Untergrenze ist die Gegenprobe: Zieht der Fang um oder wird
         * umgeschrieben, liefe der Ausdruck ins Leere und dieser Wächter melde
         * „in Ordnung" für eine Datei, die er nicht gelesen hat.
         */
        $this->assertGreaterThan(
            0,
            $checked,
            'In '.self::FILE.' wird kein AgentException-Fang mit einer Feldmeldung gefunden — '
            .'dann prüft dieser Wächter nichts.',
        );

        $this->assertSame([], $broken, sprintf(
            "Hier wird ein Fehler des Agenten ungeprüft an ein Feld geschrieben:\n  %s\n\n".
            'Nur AgentException::BAD_REQUEST ist eine Aussage über die Eingabe. Alles andere ist ein '.
            'Zustand des Servers und gehört an die Zusammenfassung, ohne ein Feld rot zu machen.',
            implode("\n  ", $broken),
        ));
    }
}
