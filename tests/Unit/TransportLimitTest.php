<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Ops\FilesRead;
use SrvPanel\Agent\Ops\FilesWrite;
use Tests\Support\WithoutPhpComments;

/**
 * Eine erklärte Grenze muss auch erreichbar sein.
 *
 * **Der Anlass ist Befund 12b aus `docs/62`.** `FilesWrite::MAX_BYTES` stand
 * auf 2 MiB, `Connection::REQUEST_MAX` auf 1 MiB — und der Inhalt einer Datei
 * reist als Feld *in* dieser einen JSON-Zeile. Die Hälfte der erklärten Grenze
 * war damit nie zu erreichen. Eine Datei zwischen den beiden Zahlen öffnete
 * sich im Editor, liess sich bearbeiten und **nie speichern**; der Kunde
 * bekam „Anfrage überschreitet 1 MiB", also eine Auskunft über das Protokoll
 * statt über seine Datei.
 *
 * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
 *
 * **Und die Begründung dazu war beim ersten Ausschreiben falsch.** Sie lautete,
 * deutscher Text wachse als JSON um den Faktor 1,71, weil aus `ü` die sechs
 * Zeichen `\u00fc` werden.
 * Das stimmt für `json_encode` mit seinen Voreinstellungen — und der Klient
 * setzt seit dem 11. August `JSON_UNESCAPED_UNICODE`. Gemessen am 19. August
 * 2026 mit den Fahnen, die er wirklich führt: deutsche Prosa **1,02×**, PHP mit
 * Zeichenketten 1,12×, Steuerzeichen 6×, Umlaute **1,00×**.
 *
 * > **Ein Faktor, der an anderen Fahnen gemessen wurde, gehört zu einer anderen
 * > Leitung.**
 *
 * Der Schluss blieb derselbe, die Zahl nicht — und deshalb rechnet dieser
 * Wächter, statt einen Faktor zu führen.
 */
final class TransportLimitTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Keine erklärte Grenze ist grösser als der Weg dorthin.
     */
    public function test_no_declared_limit_exceeds_the_transport(): void
    {
        $this->assertLessThan(
            Connection::REQUEST_MAX,
            Connection::CONTENT_MAX,
            'Für die Hülle der Anfrage bleibt kein Platz — dann ist CONTENT_MAX keine Grenze, sondern eine Zusage ohne Deckung.',
        );

        $this->assertLessThanOrEqual(
            Connection::CONTENT_MAX,
            FilesWrite::MAX_BYTES,
            'files.write erklärt mehr, als durch eine Anfrage passt — genau der Zustand von Befund 12b.',
        );
    }

    /**
     * Was sich öffnen lässt, lässt sich auch zurückschreiben.
     *
     * **Andersherum ist es eine Falle mit Speicherknopf:** Die Datei erscheint,
     * die Änderung entsteht, und erst das Speichern sagt nein — nach der
     * Arbeit, nicht davor.
     */
    public function test_what_opens_can_be_written_back(): void
    {
        $this->assertLessThanOrEqual(
            FilesWrite::MAX_BYTES,
            FilesRead::MAX_BYTES,
            'Der Editor öffnet mehr, als sich speichern lässt.',
        );
    }

    /**
     * Und der Platz für die Hülle wird gerechnet, nicht geschätzt.
     *
     * Der Abzug in {@see Connection::CONTENT_MAX} ist eine Zahl, die jemand
     * hingeschrieben hat. Dieser Fall baut die Zeile, die dabei entsteht —
     * grosszügig lange Namen und ein tiefer Pfad —, und misst nach.
     */
    public function test_a_full_payload_still_fits_into_one_request(): void
    {
        $zeile = strlen((string) json_encode([
            'v' => 1,
            'id' => str_repeat('a', 16),
            'op' => 'files.write',
            'actor' => ['id' => 999999, 'email' => str_repeat('n', 254).'@'.str_repeat('d', 254)],
            'args' => (object) [
                'subscription' => str_repeat('s', 253),
                'user' => str_repeat('u', 32),
                'path' => '/'.str_repeat(str_repeat('v', 255).'/', 15).'datei.php',
                'content' => str_repeat('a', Connection::CONTENT_MAX),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertLessThanOrEqual(
            Connection::REQUEST_MAX,
            $zeile,
            sprintf(
                'Eine volle Nutzlast misst als Zeile %d Byte und passt nicht in %d — der Abzug für die Hülle ist zu knapp.',
                $zeile,
                Connection::REQUEST_MAX,
            ),
        );
    }

    /**
     * Der Klient misst die kodierte Zeile und schätzt sie nicht.
     *
     * **Ein Faktor wäre hier das Falsche.** Wie viel eine Datei als JSON misst,
     * hängt daran, was maskiert werden muss — zwischen 1,00× und 6× gemessen.
     * Die fertige Zeile liegt im Klienten vor; wer an ihr misst, braucht keinen
     * Faktor und kann sich also auch nicht in ihm irren.
     */
    public function test_the_client_measures_the_encoded_line(): void
    {
        $quelltext = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Client.php'),
        );

        $this->assertStringContainsString(
            'strlen($json) > Connection::REQUEST_MAX',
            $quelltext,
            'Der Klient prüft die Grösse der Anfrage nicht, bevor er sie schickt — der Agent meldet sie dann als Protokollfehler.',
        );

        // **Und er kodiert nur einmal.** Stünden die Fahnen zweimal da, misst
        // die eine Fassung irgendwann eine Zeile, die die andere anders
        // schreibt — und `JSON_UNESCAPED_UNICODE` ist der Unterschied zwischen
        // 1,02x und 1,71x für deutschen Text.
        $this->assertSame(
            1,
            substr_count($quelltext, 'JSON_UNESCAPED_UNICODE'),
            'Die Fahnen zum Kodieren stehen mehr als einmal in Client.php — zwei Fassungen derselben Zeile laufen auseinander.',
        );
    }
}
