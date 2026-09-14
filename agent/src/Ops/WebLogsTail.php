<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Site;

/**
 * Die letzten Zeilen eines Protokolls einer Domain.
 *
 * **Auch hier kommt kein Pfad von aussen.** Übergeben werden Abonnement,
 * Domain und eine Sorte aus einer festen Liste; der Pfad entsteht in
 * {@see Site}. Eine Operation „lies diese Datei" mit einem Pfad als Argument
 * wäre der kürzeste Weg von einem angemeldeten Kunden zu `/etc/shadow` — und
 * jede nachträgliche Prüfung des Pfades hätte irgendwann eine Lücke.
 *
 * **Von hinten gelesen.** Ein Zugriffsprotokoll wird hunderte Megabyte gross.
 * `file()` läse es ganz in den Speicher, und der Agent hat, anders als das
 * Panel, keinen Grund für ein grosszügiges Limit. Gelesen wird deshalb
 * rückwärts in Blöcken, bis genug Zeilenumbrüche beisammen sind.
 */
final class WebLogsTail implements Op
{
    public const MAX_LINES = 500;

    /** Ein Block, wie er rückwärts gelesen wird. */
    private const CHUNK = 8192;

    /** Mehr als das wird nicht zurückgegeben, egal wie lang die Zeilen sind. */
    private const MAX_BYTES = 512 * 1024;

    public static function name(): string
    {
        return 'web.logs.tail';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $site = Site::fromArgs([
            'subscription' => $args['subscription'] ?? null,
            'user' => $args['user'] ?? null,
            'domain' => $args['domain'] ?? null,
            'document_root' => SubscriptionProvision::DOCUMENT_ROOT,
        ]);

        $kind = Guard::enum($args['kind'] ?? 'access', ['access', 'error'], 'kind');
        $lines = $this->lines($args['lines'] ?? 100);

        $path = $kind === 'access' ? $site->accessLog() : $site->errorLog();

        if (! is_file($path)) {
            // Kein Protokoll ist kein Fehler: Eine Domain, die noch niemand
            // aufgerufen hat, hat keines. Eine Ausnahme hier führte im Panel
            // zu einer roten Meldung für den Normalfall am ersten Tag.
            return ['path' => $path, 'lines' => [], 'exists' => false, 'size' => 0];
        }

        $found = self::tail($path, $lines);

        /*
         * **`kind` steht hier nicht mehr.** Es wurde zurückgespiegelt und von
         * niemandem gelesen: Der Umschalter der Seite nimmt seinen Wert aus
         * `props.kind`, das der Controller selbst setzt. Dasselbe galt für
         * `origin` bei `system.logs.tail`, und dort ist es aus demselben Grund
         * entfernt worden statt in einer Ausnahmeliste zu landen
         * (`docs/914 §12`, Fund 3) — eine Ausnahme deckt den Befund zu, den der
         * Wächter darüber machen soll.
         *
         * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen
         * > nicht von einem zu unterscheiden, das es nicht gibt.**
         */
        return [
            'path' => $path,
            'lines' => $found['lines'],
            'exists' => true,
            'size' => (int) filesize($path),

            // **Die beiden Gründe, aus denen ein Fenster unvollständig sein
            // kann.** Sie wurden ab `docs/914` gesendet und bis zum
            // 14. September 2026 vom Controller weggeworfen; seit `docs/919`
            // baut die Domainseite ihre Fusszeile und ihren Knopf daraus.
            'complete' => $found['complete'],
            'capped' => $found['capped'],
        ];
    }

    /**
     * Die letzten `$count` Zeilen einer Datei — und wovon sie begrenzt sind.
     *
     * ## Warum die Antwort mehr als Zeilen trägt
     *
     * Dieser Leser hört aus **drei** Gründen auf, und von aussen sehen alle
     * drei gleich aus: Die Datei war zu Ende, es waren genug Zeilen
     * beisammen, oder {@see self::MAX_BYTES} war erreicht. Wer nur die Zeilen
     * bekommt, kann „das ist alles" nicht von „mehr war nicht zu holen"
     * unterscheiden — und eine Oberfläche, die das trotzdem behauptet, sagt
     * etwas, das sie nicht weiss.
     *
     * Gemessen am 13. September 2026 (`docs/914 §1`), 500 Zeilen à 4 KiB:
     * Der Bytedeckel liefert **128** Zeilen, und `truncated` der Operation
     * stand dabei auf `false` — die Seite meldete eine vollständige Sicht auf
     * einen Ausschnitt. Die Schwelle ist `512 KiB ÷ 500 = 1048 B` je Zeile.
     *
     * > **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht derselbe
     * > Grund — und die Abhilfe für den einen lässt den anderen stehen.**
     *
     * ## `complete` hängt an der Lage und nicht am Ausstieg
     *
     * Gefragt wird `$position === 0` **nach** der Schleife, gleich wie sie
     * geendet hat. Eine Datei, die ganz in einen Block passt und trotzdem mehr
     * Zeilen hat als gewünscht, verlässt die Schleife über das `break` — und
     * ist vollständig gelesen. Wer stattdessen den Ausstiegsgrund merkte,
     * nennte sie unvollständig.
     *
     * @return array{lines: list<string>, complete: bool, capped: bool}
     */
    public static function tail(string $path, int $count): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Das Protokoll liess sich nicht öffnen.', ['path' => $path]);
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);

            if ($position === false || $position === 0) {
                // Eine leere Datei ist vollständig gelesen. `complete = false`
                // liesse die Seite „weiter zurück gibt es mehr" anbieten, wo
                // nichts ist.
                return ['lines' => [], 'complete' => true, 'capped' => false];
            }

            $text = '';

            while ($position > 0 && strlen($text) < self::MAX_BYTES) {
                $step = (int) min(self::CHUNK, $position);
                $position -= $step;

                fseek($handle, $position, SEEK_SET);
                $text = (string) fread($handle, $step).$text;

                // Ein Umbruch mehr als Zeilen gewünscht: Der erste Block endet
                // in aller Regel mitten in einer Zeile, und die gehört nicht
                // angeschnitten zurückgegeben.
                if (substr_count($text, "\n") > $count) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $all = explode("\n", rtrim($text, "\n"));
        $capped = $position > 0 && strlen($text) >= self::MAX_BYTES;

        /*
         * **Beim Bytedeckel wird die erste Zeile weggeworfen, und das ist kein
         * Datenverlust.** Sie ist keine Zeile: Der Leser fängt mitten in einer
         * an, und `explode` macht daraus einen ersten Eintrag ohne Anfang.
         *
         * Der Kommentar an der Schleife sagt die Absicht seit jeher — *„die
         * gehört nicht angeschnitten zurückgegeben"* —, und sein Schutz ist
         * `substr_count($text, "\n") > $count`: ein Umbruch mehr als
         * gewünscht, damit `array_slice` das Bruchstück abschneidet. Diese
         * Bedingung greift beim Bytedeckel **nie**.
         *
         * > **Ein Schutz, der an einer von drei Abbruchbedingungen hängt,
         * > schützt die beiden anderen nicht — und welche greift, entscheidet
         * > der Inhalt der Datei.**
         *
         * Gefunden am 13. September 2026 auf `cloudsrv24` (`docs/916 §3`): Die
         * oberste Zeile trug kein `[2026-…]`, sondern nur `xxxx…`.
         */
        if ($capped && count($all) > 1) {
            array_shift($all);
        }

        return [
            'lines' => array_values(array_slice($all, -$count)),
            'complete' => $position === 0,

            // **Der Deckel zählt nur, solange etwas ungelesen blieb.** Endet
            // die Schleife am Anfang der Datei und ist der Text zufällig
            // genau so gross, wäre `capped` sonst wahr für eine vollständig
            // gelesene Datei.
            'capped' => $capped,
        ];
    }

    private function lines(mixed $value): int
    {
        $lines = Guard::int($value, 'lines');

        if ($lines < 1 || $lines > self::MAX_LINES) {
            throw AgentException::badRequest(
                sprintf('lines liegt zwischen 1 und %d.', self::MAX_LINES),
                ['lines' => $lines],
            );
        }

        return $lines;
    }
}
