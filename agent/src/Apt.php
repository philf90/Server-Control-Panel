<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die eine Stelle, die `apt-get update` ruft — und seine Fehlschläge liest.
 *
 * ## Der Fund, der diese Klasse ausgelöst hat (M5, `docs/81 §2.1`)
 *
 * > **`apt-get update` gibt 0 zurück, auch wenn jede einzelne Quelle
 * > unerreichbar war.**
 *
 * Gemessen am 24. August 2026 mit Gegenprobe gegen eine Quelle auf
 * `127.0.0.1:1`: `rc=0`, dazu zwei `W:`-Zeilen auf stderr. Das ist keine
 * Nachlässigkeit von apt, sondern seine Zusage — der Rückgabewert beantwortet
 * nicht „habe ich alle Quellen erreicht", sondern „habe ich danach einen
 * benutzbaren Zustand". Und den hat er: Die alte Liste bleibt liegen. Die
 * Meldung sagt es wörtlich, *„They have been ignored, or old ones used
 * instead."*
 *
 * > **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine
 * > Prüfung — er ist eine Zeile, die aussieht wie eine.**
 *
 * Die Auskunft ist also **da**; {@see Result} trennt `stdout` und `stderr`
 * längst. Sie hat an dieser Stelle nur niemand gelesen — an drei Stellen
 * nicht, jede mit ihrem eigenen `if (! $update->successful())`.
 *
 * ## Warum nicht einfach `--error-on=any`
 *
 * Weil die Fahne alles oder nichts ist. Sie ist die richtige Härte für einen
 * Lauf, der genau eine Quelle braucht, und die falsche für einen, der alle
 * nachsieht: Eine vorübergehend unerreichbare Drittquelle blockierte damit ein
 * Sicherheitsupdate aus dem Ubuntu-Archiv.
 *
 * > **Eine Härte, die nur einheitlich zu haben ist, gehört nicht an eine
 * > Stelle, an der die Aufrufer verschieden entscheiden müssen.**
 *
 * Deshalb entscheidet hier niemand. Diese Klasse **liest** und gibt je Quelle
 * einen Ausgang zurück; was daraus folgt, entscheidet der Aufrufer:
 * `php.version.install` bricht an einer toten Sury ab, `pg.server.install`
 * nennt sie als mögliche Ursache, und `system.packages.list` wird sie später
 * neben der Zahl zeigen.
 *
 * ## Warum der Rückgabewert trotzdem stehenbleibt
 *
 * Er ist nicht falsch, nur unvollständig: `apt-get update` endet sehr wohl
 * ungleich 0, wenn die Sperre klemmt, eine Quelldatei kaputt ist oder ein
 * Schlüssel fehlt. Beides wird gefragt — {@see self::reachedEverything()} für
 * die Quellen, {@see Result::successful()} für den Lauf.
 */
final class Apt
{
    /**
     * Wie `apt-get update` gerufen wird.
     *
     * `-qq` ist bewusst dabei: Der Fortschrittsbalken schreibt sonst mehrere
     * hundert Zeilen `\r` auf stderr, und die stünden zwischen den `W:`-Zeilen,
     * die hier gesucht werden.
     *
     * @var list<string>
     */
    public const UPDATE_ARGUMENTS = ['update', '-qq'];

    /**
     * @param  list<array{uri: string, base: string, reason: string}>  $unreachable
     */
    private function __construct(
        public readonly Result $result,
        public readonly array $unreachable,
    ) {}

    /**
     * Paketlisten auffrischen und nachsehen, welche Quelle dabei ausgefallen
     * ist.
     *
     * Der Lauf geht über {@see Context::stream()} und nicht über den Runner
     * unmittelbar: Ein `apt-get update` mit kalter Liste dauert auf einem
     * frisch aufgesetzten Server über eine Minute, und in dieser Zeit soll das
     * Panel etwas zu zeigen haben.
     */
    public static function refresh(Context $context, int $timeout = 300): self
    {
        return self::of($context->stream('apt-get', self::UPDATE_ARGUMENTS, $timeout));
    }

    /**
     * Aus dem Ergebnis eines Laufs die Auskunft machen — die Naht.
     *
     * **Getrennt von {@see self::refresh()}, damit die Regel ohne Server
     * prüfbar ist.** Derselbe Schnitt wie bei `PhpVersions::missing()` und
     * `Pg\Clusters::parse()`, und hier aus einem besonderen Grund: `Runner`
     * und `Context` sind `final`, es gibt also keine Attrappe. Ohne diese Naht
     * wäre der Weg dahinter ungeprüft — und genau das ist der Satz, an dem in
     * P6 zwei Fehler wochenlang unbemerkt blieben:
     *
     * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der
     * > Weg dahinter auch nicht.**
     *
     * `AptResultTest` baut hier ein {@see Result} mit Rückgabewert 0 und
     * `W:`-Zeilen auf `stderr` — also genau die Lage, in der der Code vor dem
     * 24. August 2026 Erfolg meldete.
     */
    public static function of(Result $result): self
    {
        return new self($result, self::readFailures($result->stderr));
    }

    /**
     * Die `W:`-Zeilen von `apt-get update`, je Quelle eine.
     *
     * **Rein und öffentlich, damit ein Wächter sie mit eigenen Prüfkörpern
     * messen kann.** `AptResultTest` baut seine Zeilen selbst, Zeile für Zeile
     * — so wie `ArchiveDepthTest` seine Archive baut. Ein Prüfkörper vom
     * `apt-get update` dieser Maschine enthält genau den Fall nicht, um den es
     * geht: eine Quelle, die nicht antwortet.
     *
     * Drei Dinge, die dieser Leser wissen muss:
     *
     * 1. **Die Zusammenfassung ist keine Quelle.** Hinter den `Failed to
     *    fetch`-Zeilen steht *„W: Some index files failed to download."* — eine
     *    Zeile über alle. Wer sie mitzählt, meldet bei einer toten Quelle zwei.
     * 2. **`E:` steht dort genauso.** Mit `--error-on=any` schreibt apt
     *    dieselbe Zeile mit anderem Buchstaben (gemessen). Diese Klasse setzt
     *    die Fahne nicht, aber der Text ist derselbe, und zwei Leser für eine
     *    Zeile sind zwei Fassungen derselben Regel.
     * 3. **Die Begründung steht dahinter und ist der Teil, den ein Mensch
     *    braucht.** *„Could not connect"* und *„404 Not Found"* sind zwei sehr
     *    verschiedene Lagen, und beide sehen ohne diesen Text gleich aus.
     *
     * @return list<array{uri: string, base: string, reason: string}>
     */
    public static function readFailures(string $stderr): array
    {
        $failures = [];

        foreach (explode("\n", $stderr) as $line) {
            if (preg_match('/^[WE]: Failed to fetch (\S+)\s*(.*?)\s*$/D', rtrim($line, "\r"), $match) !== 1) {
                continue;
            }

            $failures[] = [
                'uri' => $match[1],
                'base' => self::base($match[1]),
                'reason' => $match[2],
            ];
        }

        return $failures;
    }

    /** Hat der Lauf jede Quelle erreicht? */
    public function reachedEverything(): bool
    {
        return $this->unreachable === [];
    }

    /**
     * Welche der ausgefallenen Quellen liegt unter einer dieser Adressen?
     *
     * **Verglichen wird der Anfang der vollen Adresse und nicht die abgeleitete
     * Basis.** Die Ableitung in {@see self::base()} ist eine Vermutung über den
     * Aufbau des Depots und liegt bei einem flachen Depot daneben; der Anfang
     * der Adresse liegt nie daneben. Die Basis ist für den Leser da, der
     * Vergleich für die Entscheidung.
     *
     * Beide Seiten bekommen einen abschliessenden Schrägstrich, bevor
     * verglichen wird — sonst hielte `https://host/php` auch
     * `https://host/php-alt/dists/…` für seine eigene Quelle.
     *
     * @param  list<string>  $uris  Die Adressen einer Quelle, wie sie in `URIs:` stehen
     * @return null|array{uri: string, base: string, reason: string}
     */
    public function hitting(array $uris): ?array
    {
        foreach ($this->unreachable as $failure) {
            foreach ($uris as $uri) {
                if ($uri !== '' && str_starts_with(rtrim($failure['uri'], '/').'/', rtrim($uri, '/').'/')) {
                    return $failure;
                }
            }
        }

        return null;
    }

    /**
     * Ein Satz über die ausgefallenen Quellen — für die Meldung an einen
     * Menschen.
     *
     * Leer, wenn alles erreichbar war. **Der Aufrufer prüft das und hängt
     * nicht blind an**: Ein „(keine Quelle ausgefallen)" am Ende einer
     * Fehlermeldung schickt den Leser dorthin, wo nichts zu finden ist.
     */
    public function summary(): string
    {
        if ($this->unreachable === []) {
            return '';
        }

        return implode('; ', array_map(
            static fn (array $failure): string => $failure['base'].' ('.$failure['reason'].')',
            $this->unreachable,
        ));
    }

    /**
     * Aus der Adresse einer Indexdatei die der Quelle machen — so weit das
     * geht.
     *
     * `…/ubuntu/dists/noble/InRelease` wird zu `…/ubuntu/`. Bei einem flachen
     * Depot gibt es kein `/dists/`; dann bleibt das Verzeichnis stehen, in dem
     * die Datei liegt. Das ist eine **Vermutung** und deshalb nur für die
     * Anzeige — entschieden wird über {@see self::hitting()}.
     */
    private static function base(string $uri): string
    {
        $cut = strpos($uri, '/dists/');

        if ($cut !== false) {
            return substr($uri, 0, $cut + 1);
        }

        $slash = strrpos($uri, '/');

        return $slash === false ? $uri : substr($uri, 0, $slash + 1);
    }
}
