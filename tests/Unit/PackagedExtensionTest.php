<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Jede PHP-Erweiterung, die dieser Quelltext benutzt, steht in der Paketierung.
 *
 * ## Der Fund
 *
 * `docs/117 §9` Punkt 5, gemessen am 16. September 2026: Acht Dateien benutzen
 * `ZipArchive`, und **weder `packaging/nfpm.yaml` noch `composer.json` nannten
 * `zip`**. Auf `cloudsrv24` liegt `php8.4-zip` vielleicht — weil es jemand
 * anderes mitgebracht hat.
 *
 * > **Eine Erweiterung, die der Code benutzt und die Paketierung nicht nennt,
 * > ist auf jedem Server vorhanden, auf dem sie zufällig jemand anderes
 * > mitgebracht hat.**
 *
 * Seit P8 wiegt das schwerer als vorher: `Files\Compress` war ein Merkmal,
 * `backup.create` ist eine Stufe. Und **eine der acht Dateien läuft unter
 * php-fpm** — `App\Support\Backups\Restore` liest das Verzeichnis einer
 * Sicherung im Web-Request, nicht auf der Kommandozeile.
 *
 * ## Warum eine Liste hier richtig ist und sonst nicht
 *
 * {@see self::SHIPPED_WITH_PHP} ist kein Gedächtnis, sondern eine **Messung**:
 * Welches Debian-Paket eine Erweiterung mitbringt, kann dieser Wächter nicht
 * erfragen — `dpkg -S` beantwortet das für die Maschine, auf der er gerade
 * läuft, und das ist im Container eine andere als auf dem Zielserver. Jeder
 * Eintrag trägt deshalb seine Messung im Wort.
 *
 * Gemessen wurde mit `ls /usr/lib/php/20240924/<ext>.so` und `dpkg -S`
 * darauf — auf `php8.4-cli` aus dem Ubuntu-Archiv, derselben Reihe, die
 * `nfpm.yaml` verlangt.
 *
 * ## Was er nicht kann
 *
 * Ob das genannte Paket auf dem Zielserver **wirklich** installiert ist, sagt
 * nur der Server; der Griff ist `php -m`. Dieser Wächter hält allein, dass die
 * Paketierung danach fragt.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.**
 */
final class PackagedExtensionTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Woran eine Erweiterung im Quelltext zu erkennen ist.
     *
     * Klassen und Funktionen, keine Konstanten: `CURLOPT_*` steht auch in einem
     * Kommentar, `curl_init(` nicht.
     *
     * @var array<string,list<string>>
     */
    private const MARKERS = [
        'zip' => ['ZipArchive'],
        'curl' => ['curl_init', 'curl_exec'],
        'posix' => ['posix_getpwnam', 'posix_getgrnam', 'posix_geteuid', 'posix_getuid'],
        'pcntl' => ['pcntl_fork', 'pcntl_signal', 'pcntl_waitpid', 'pcntl_async_signals'],
        'sockets' => ['socket_create', 'socket_connect'],
        'openssl' => ['openssl_sign', 'openssl_pkey_get_private', 'openssl_x509_parse', 'openssl_random_pseudo_bytes'],
        'mbstring' => ['mb_substr', 'mb_strlen', 'mb_stripos', 'mb_strtolower'],
        'intl' => ['idn_to_ascii', 'Collator'],
        'gd' => ['imagecreatetruecolor', 'imagepng'],
        'bcmath' => ['bcadd', 'bcmul'],
        'ftp' => ['ftp_connect'],
        'ssh2' => ['ssh2_connect'],
    ];

    /**
     * Was `php8.4-cli` ohnehin mitbringt — mit der Messung als Grund.
     *
     * @var array<string,string>
     */
    private const SHIPPED_WITH_PHP = [
        'posix' => '`/usr/lib/php/20240924/posix.so` gehört laut `dpkg -S` zu `php8.4-common`, '.
            'und `php8.4-cli` hängt daran.',
        'sockets' => '`sockets.so` gehört ebenfalls zu `php8.4-common`.',
        'pcntl' => 'Eingebaut — es gibt keine `pcntl.so`. Nur in der CLI-SAPI, und benutzt wird '.
            'es allein dort (Agent und ein Artisan-Kommando).',
        'openssl' => 'Eingebaut — es gibt keine `openssl.so`.',
    ];

    /** Jede benutzte Erweiterung steht in `nfpm.yaml` oder bringt PHP sie mit. */
    public function test_every_extension_the_code_uses_is_named_in_the_packaging(): void
    {
        $benutzt = $this->used();

        /*
         * **Die Untergrenze.** Ohne sie wäre der Wächter grün, sobald der Leser
         * nichts mehr findet — und genau dann prüft er nichts.
         */
        $this->assertGreaterThanOrEqual(5, count($benutzt), sprintf(
            'Nur %d Erweiterungen im Quelltext gefunden — dann liest dieser Wächter ihn nicht mehr.',
            count($benutzt),
        ));

        $genannt = $this->packagedExtensions();

        /*
         * **Die zweite Untergrenze.** Sie fängt den Fall, dass der Leser der
         * YAML-Datei ins Leere greift — dann stünde jede Erweiterung als
         * fehlend da, und der Befund wäre einer über den Leser.
         */
        $this->assertGreaterThanOrEqual(2, count($genannt), sprintf(
            'Nur %d `php8.4-*`-Pakete in `nfpm.yaml` gefunden — dann liest dieser Wächter die '.
            'Paketierung nicht mehr.',
            count($genannt),
        ));

        $fehlend = [];

        foreach ($benutzt as $erweiterung => $dateien) {
            if (isset(self::SHIPPED_WITH_PHP[$erweiterung])) {
                continue;
            }

            if (in_array($erweiterung, $genannt, true)) {
                continue;
            }

            $fehlend[] = sprintf(
                '%s — benutzt in %d Datei(en), zuerst %s',
                $erweiterung,
                count($dateien),
                $dateien[0],
            );
        }

        $this->assertSame([], $fehlend, sprintf(
            "Diese Erweiterungen benutzt der Code und die Paketierung nennt sie nicht:\n\n  %s\n\n".
            'Entweder gehört `php8.4-<name>` in die `depends:` von `packaging/nfpm.yaml` — oder '.
            'die Erweiterung bringt PHP selbst mit, und dann gehört sie mit der **Messung** '.
            '(`dpkg -S` auf ihre `.so`) nach self::SHIPPED_WITH_PHP.',
            implode("\n  ", $fehlend),
        ));
    }

    /**
     * Die Gegenrichtung: kein Eintrag steht für etwas, das niemand benutzt.
     *
     * **So entsteht ein toter Eintrag wirklich** — eine Erweiterung fliegt aus
     * dem Code, und die Zeile bleibt liegen. Danach liest der Nächste eine
     * Messung über etwas, das es nicht mehr gibt, und glaubt sie.
     */
    public function test_no_exemption_stands_for_an_extension_nobody_uses(): void
    {
        $benutzt = $this->used();

        foreach (self::SHIPPED_WITH_PHP as $erweiterung => $grund) {
            $this->assertNotSame('', trim($grund), sprintf('Die Ausnahme „%s" trägt keinen Grund.', $erweiterung));

            $this->assertArrayHasKey($erweiterung, $benutzt, sprintf(
                'Die Ausnahme „%s" steht für eine Erweiterung, die dieser Quelltext nicht mehr '.
                'benutzt. Sie geht mit ihr.',
                $erweiterung,
            ));
        }
    }

    /**
     * Welche `php8.4-*`-Pakete die `depends:` wirklich nennt.
     *
     * **Die Kommentarzeilen fallen weg, bevor gesucht wird — und das ist
     * bezahlt.** Der erste Wurf fragte `str_contains($nfpm, 'php8.4-zip')`, und
     * der Eingriff, der die Zeile auskommentierte, liess ihn **grün**: Der
     * Absatz darüber, der die Messung erklärt, schreibt `php8.4-zip` wörtlich
     * hin.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     *
     * Gesucht wird deshalb der **Listeneintrag** und nicht die Zeichenkette:
     * `- php8.4-<name>` am Anfang einer Zeile, nach dem Abstreifen der
     * `#`-Kommentare.
     *
     * @return list<string>
     */
    private function packagedExtensions(): array
    {
        $roh = (string) file_get_contents($this->root().'/packaging/nfpm.yaml');
        $ohneKommentare = (string) preg_replace('/^\s*#.*$/m', '', $roh);

        preg_match_all('/^\s*-\s*php8\.4-([a-z0-9]+)\s*$/m', $ohneKommentare, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Welche Erweiterung wo benutzt wird — Kommentare zählen nicht mit.
     *
     * `WithoutPhpComments::withoutComments()` fragt `token_get_all()`, also den
     * Parser. Ohne ihn
     * hielte der Absatz, der diese Behebung erklärt, `ZipArchive` für einen
     * Gebrauch.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     *
     * @return array<string,list<string>>
     */
    private function used(): array
    {
        $treffer = [];

        foreach ($this->phpFiles() as $datei) {
            $code = $this->withoutComments((string) file_get_contents($datei));
            $kurz = str_replace($this->root().'/', '', $datei);

            foreach (self::MARKERS as $erweiterung => $namen) {
                foreach ($namen as $name) {
                    if (preg_match('/(?<![\w\\\\])'.preg_quote($name, '/').'(?![\w])/', $code) === 1) {
                        $treffer[$erweiterung][] = $kurz;

                        break;
                    }
                }
            }
        }

        foreach ($treffer as $erweiterung => $dateien) {
            sort($dateien);
            $treffer[$erweiterung] = array_values(array_unique($dateien));
        }

        ksort($treffer);

        return $treffer;
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $dateien = [];

        foreach (['app', 'agent/src', 'config', 'routes', 'database'] as $ordner) {
            /** @var SplFileInfo $datei */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root().'/'.$ordner, FilesystemIterator::SKIP_DOTS),
            ) as $datei) {
                if ($datei->isFile() && $datei->getExtension() === 'php') {
                    $dateien[] = $datei->getPathname();
                }
            }
        }

        sort($dateien);

        return $dateien;
    }
}
