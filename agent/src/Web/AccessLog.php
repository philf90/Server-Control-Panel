<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Web;

use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Eine Zeile aus einem Zugriffsprotokoll, zerlegt — und ein Tag gezählt.
 *
 * ## Warum das Zerlegen an den Anführungszeichen trägt
 *
 * Die Sorge lag nahe, ein User-Agent mit einem Anführungszeichen mache die
 * Zeile mehrdeutig. **Gemessen ist sie es nicht** (`docs/128` M4): nginx
 * maskiert, und zwar nicht mit einem Gegenschrägstrich, sondern als
 * `\x22` — vier Zeichen, von denen keines ein Anführungszeichen ist. Eine
 * Zeile zerfällt damit an `"` in genau sieben Stücke, gleich was ein Besucher
 * schickt.
 *
 * > **Wer `\x22` zurückübersetzt und dann trennt, zerlegt eine Zeile, die es
 * > nie gab.** Hier wird deshalb erst getrennt und nie demaskiert.
 *
 * ## Die zwei Zeitalter
 *
 * Bis zum 20. September 2026 schrieb jede Domain nginx' `combined`; seitdem
 * `srvpanel` — dieselben acht Felder und zwei mehr am Ende
 * ({@see SiteTemplate::httpConfig()}).
 *
 * | | `combined` | `srvpanel` |
 * |---|---|---|
 * | Rumpf | `$body_bytes_sent` | dasselbe |
 * | gesendet | — | `$bytes_sent` |
 * | empfangen | — | `$request_length` |
 *
 * Unterschieden wird an dem, was **hinter** dem letzten Anführungszeichen
 * steht: nichts im alten Zeitalter, zwei Zahlen im neuen. Nicht an einem
 * Datum — ein Datum wüsste nicht, wann die Vorlage auf diesem Server
 * ausgerollt wurde, und eine Datei kann beides enthalten.
 *
 * Eine alte Zeile wird **gelesen und nicht gezählt**: Ihr `body_bytes_sent`
 * ist bei einem `304` eine Null, während Bytes hinausgehen, und eine Summe
 * aus beiden Zeitaltern wäre eine Zahl, die niemand nachrechnen kann.
 *
 * > **Ein Zähler, der zwei Formate mischt, liefert eine Zahl, die aussieht wie
 * > eine Zahl.**
 *
 * ## Was diese Klasse nicht tut
 *
 * Sie öffnet keine Datei nach eigener Wahl und kennt keinen Pfad — den baut
 * {@see Site} aus geprüften Bestandteilen. Und sie sagt nichts
 * über Besucher, Sitzungen oder Herkunft: gezählt werden Anfragen und Bytes.
 */
final class AccessLog
{
    /** Die Monatskürzel, die nginx schreibt — englisch, unabhängig von der Locale. */
    private const MONTHS = [
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
        'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
    ];

    /**
     * Eine Zeile, zerlegt — oder `null`, wenn sie keine ist.
     *
     * `null` heisst „nicht deutbar" und nicht „leer": Eine Zeile, die hier
     * herausfällt, wird gezählt ({@see self::countFile()}), damit eine Datei,
     * die zur Hälfte aus Unrat besteht, nicht wie eine leise Domain aussieht.
     *
     * @return array{day: string, status: int, sent: int|null, received: int|null}|null
     */
    public static function parse(string $line): ?array
    {
        $teile = explode('"', rtrim($line, "\r\n"));

        if (count($teile) !== 7) {
            return null;
        }

        $tag = self::day($teile[0]);

        if ($tag === null) {
            return null;
        }

        // Zwischen Anfrage und Verweis: ` <status> <body_bytes_sent> `
        $mitte = preg_split('/\s+/', trim($teile[2])) ?: [];

        if (count($mitte) < 1 || preg_match('/^\d{3}$/D', $mitte[0]) !== 1) {
            return null;
        }

        // Hinter dem User-Agent: nichts (combined) oder zwei Zahlen (srvpanel).
        $ende = preg_split('/\s+/', trim($teile[6])) ?: [];
        $ende = array_values(array_filter($ende, static fn (string $s): bool => $s !== ''));

        $neu = count($ende) === 2
            && preg_match('/^\d+$/D', $ende[0]) === 1
            && preg_match('/^\d+$/D', $ende[1]) === 1;

        return [
            'day' => $tag,
            'status' => (int) $mitte[0],
            'sent' => $neu ? (int) $ende[0] : null,
            'received' => $neu ? (int) $ende[1] : null,
        ];
    }

    /**
     * Der Tag aus `[20/Sep/2026:17:50:00 +0000]`, als `YYYY-MM-DD`.
     *
     * **Genommen wird, was in der Zeile steht, und nicht umgerechnet.** nginx
     * schreibt die Ortszeit des Servers samt Versatz; ein Tageswert, der sich
     * beim Lesen in eine andere Zone verschöbe, wäre an jeder Tagesgrenze eine
     * andere Zahl als die, die der Kunde in seinem Protokoll sieht.
     */
    private static function day(string $kopf): ?string
    {
        if (preg_match('/\[(\d{2})\/([A-Z][a-z]{2})\/(\d{4}):/', $kopf, $m) !== 1) {
            return null;
        }

        $monat = self::MONTHS[$m[2]] ?? null;

        if ($monat === null) {
            return null;
        }

        return $m[3].'-'.$monat.'-'.$m[1];
    }

    /**
     * Eine ganze Datei, gezählt nach Tagen.
     *
     * **Gruppiert wird nach dem Tag in der Zeile und nicht nach der Datei.**
     * `access.log.1` ist der Ertrag einer Rotation, und die läuft nicht um
     * Mitternacht — die Datei trägt deshalb regelmässig zwei Kalendertage. Wer
     * sie als „einen Tag" zählt, schiebt jede Nacht ein Stück Verkehr auf das
     * falsche Datum.
     *
     * **Der Grund dafür stand hier zuerst falsch.** „Sie läuft zu einer
     * Uhrzeit" — nachgemessen am 20. September 2026 auf `cloudsrv24` steht
     * `logrotate.timer` auf `OnCalendar=daily` mit `AccuracySec=1h`. Das ist
     * keine Uhrzeit, sondern ein **Fenster** von einer Stunde nach Mitternacht,
     * und systemd sucht sich darin einen Punkt. Die Folgerung stimmt damit
     * weiterhin, sie stimmt sogar stärker; die Begründung war geraten.
     *
     * > **Eine richtige Folgerung aus einem falschen Grund hält nur so lange,
     * > wie niemand den Grund nachprüft.**
     *
     * **`legacy` steht auch je Tag und nicht nur als Summe.** `docs/129 §5`
     * entscheidet: Gezählt wird erst ab dem Tag, der **vollständig** im neuen
     * Format geschrieben ist — „ein Tag ohne Zahlen ist ehrlicher als ein Tag
     * mit halben". Diese Entscheidung kann nur treffen, wer je Tag weiss, ob
     * eine alte Zeile darin steht; eine Summe über die Datei weiss es nicht.
     * Ein Tag, der **nur** alte Zeilen trägt, steht deshalb mit Nullen und
     * seinem `legacy` da und fehlt nicht.
     *
     * @return array{
     *     days: array<string, array{requests:int, sent:int, received:int, errors:int, legacy:int}>,
     *     lines: int, parsed: int, legacy: int, unreadable: int
     * }
     */
    public static function countFile(string $path): array
    {
        $tage = [];
        $zeilen = 0;
        $gedeutet = 0;
        $alt = 0;
        $unrat = 0;

        /*
         * **Kein Protokoll ist kein Fehler.** Eine Domain, die noch niemand
         * aufgerufen hat, hat keines — derselbe Satz wie in
         * {@see \SrvPanel\Agent\Ops\WebLogsTail}.
         *
         * Gefragt wird mit `is_file()` und nicht mit einem `@` vor `fopen()`:
         * Das Klammeraffchen unterdrückt die **Meldung** und nicht das
         * **Ereignis**, und ein Fehlerbehandler, der Warnungen einsammelt,
         * sieht es trotzdem. Gemessen am 20. September 2026: `phpunit` allein
         * sagte `OK`, `php artisan test` derselben Datei eine Warnung.
         *
         * > **Ein unterdrückter Fehler ist keiner, der nicht stattgefunden
         * > hat — er ist einer, den nur dieser Aufrufer nicht sieht.**
         */
        if (! is_file($path)) {
            return ['days' => [], 'lines' => 0, 'parsed' => 0, 'legacy' => 0, 'unreadable' => 0];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['days' => [], 'lines' => 0, 'parsed' => 0, 'legacy' => 0, 'unreadable' => 0];
        }

        try {
            while (($zeile = fgets($handle)) !== false) {
                if (trim($zeile) === '') {
                    continue;
                }

                $zeilen++;
                $satz = self::parse($zeile);

                if ($satz === null) {
                    $unrat++;

                    continue;
                }

                $tag = $satz['day'];
                $tage[$tag] ??= ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0, 'legacy' => 0];

                if ($satz['sent'] === null) {
                    $alt++;
                    $tage[$tag]['legacy']++;

                    continue;
                }

                $gedeutet++;
                $tage[$tag]['requests']++;
                $tage[$tag]['sent'] += $satz['sent'];
                $tage[$tag]['received'] += $satz['received'];

                if ($satz['status'] >= 400) {
                    $tage[$tag]['errors']++;
                }
            }
        } finally {
            fclose($handle);
        }

        ksort($tage);

        return [
            'days' => $tage,
            'lines' => $zeilen,
            'parsed' => $gedeutet,
            'legacy' => $alt,
            'unreadable' => $unrat,
        ];
    }
}
