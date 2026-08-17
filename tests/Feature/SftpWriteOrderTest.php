<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Schritt, der scheitern kann, geht zuerst.
 *
 * **Der Fund** (`docs/59`, Befund 12, Phase D von Punkt 8). `Sftp::remove()`
 * schrieb die Schlüsseldatei **vor** dem Block. Bei einer kaputten
 * `sshd_config` brach der Vorgang richtig ab — und die Datei war da schon
 * gelöscht. Die Datenbank rollte die Zeile zurück; übrig blieb ein Abonnement
 * mit einem Schlüssel im Panel und keinem auf der Platte.
 *
 * > **Eine Transaktion rollt die Datenbank zurück und nicht die Platte.**
 *
 * Vorhergesagt aus dem Quelltext und danach auf `cloudsrv24` gemessen:
 * `ls -l /etc/srvpanel/ssh/` sagte `total 0`, während die Seite den Schlüssel
 * weiter aufführte.
 *
 * `sync()` prüft mit `sshd -t` und lädt neu — es gibt viele Gründe, aus denen es
 * scheitert. `write()` schreibt eine Datei. Wer den unwahrscheinlichen Schritt
 * zuerst macht, hat im wahrscheinlichen Fall schon etwas angefasst.
 *
 * **Beide Richtungen tragen dieselbe Reihenfolge**, und das ist der Grund für
 * diesen Wächter: `add()` hatte sie von Anfang an richtig, `remove()` nicht —
 * zwei Methoden derselben Klasse, zwei Reihenfolgen, und die Begründung stand
 * nur an einer.
 *
 * > **Zwei Wege durch dieselbe Sache, und die Begründung steht nur an einem.**
 */
final class SftpWriteOrderTest extends TestCase
{
    private const FILE = 'app/Support/Files/Sftp.php';

    /**
     * Die Aufrufe von `sync()` und `write()` in einer Methode, in ihrer Folge.
     *
     * @return list<string>
     */
    private function calls(string $method): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::FILE);

        // Kommentare weg: Die Begründung nennt beide Namen, und ein Wächter,
        // der Text liest, liest auch die Begründung dafür, warum er recht hat.
        $source = (string) preg_replace(['#/\*.*?\*/#su', '#//[^\n]*#'], '', $source);

        $pattern = sprintf('/public function %s\(.*?\n    \}/s', preg_quote($method, '/'));

        if (preg_match($pattern, $source, $match) !== 1) {
            return [];
        }

        preg_match_all('/\$this->(sync|write)\(/', $match[0], $found);

        return $found[1];
    }

    /** In beiden Richtungen kommt der Block vor der Schlüsseldatei. */
    public function test_the_block_goes_before_the_key_file(): void
    {
        foreach (['add', 'remove'] as $method) {
            $calls = $this->calls($method);

            $this->assertSame(
                ['sync', 'write'],
                $calls,
                sprintf(
                    'In %s() ist die Reihenfolge %s. Der Schritt, der scheitern kann, geht zuerst — '
                    .'sonst rollt die Transaktion die Datenbank zurück und die Platte nicht.',
                    $method,
                    $calls === [] ? 'nicht zu finden' : implode(', ', $calls),
                ),
            );
        }
    }
}
