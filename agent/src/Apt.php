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
     * Die Marke, mit der `apt-run simulate` seine beiden Läufe trennt.
     *
     * Sie steht hier **und** im Skript, und das ist eine Naht mit zwei Enden;
     * `AptSimulationTest` hält sie zusammen. Liefe sie auseinander, fände
     * {@see self::sections()} nichts und die Seite bekäme eine Ausnahme statt
     * einer Zahl.
     *
     * **Ohne `@see`, und das ist Absicht.** Pint macht aus einer
     * ausgeschriebenen Marke einen `use`-Eintrag — und der zöge eine Klasse aus
     * `tests/` in den Agenten, der framework- und abhängigkeitsfrei ist und sie
     * gar nicht laden könnte.
     */
    public const MARK = '### srvpanel apt-run::';

    /** Die beiden Läufe, in der Reihenfolge, in der das Skript sie schreibt. */
    public const RUNS = ['dist-upgrade', 'upgrade'];

    /**
     * Nachsehen, was apt einspielen würde — **dort, wo eingespielt wird**.
     *
     * ## Der Fund, der diese Methode ausgelöst hat (`docs/86`, Befund 6)
     *
     * Bis zum 26. August 2026 rief `system.packages.list` `apt-get -s
     * dist-upgrade` unmittelbar aus dem Agenten. Der läuft mit `PrivateTmp`,
     * `ProtectKernelTunables` und `ProtectControlGroups`; jede davon legt einen
     * Mount-Namensraum an, und darin meldet `ischroot` **rc=0**. In einem
     * chroot wendet Ubuntu sein *Phasing* nicht an — es hält nichts zurück und
     * bietet alles an.
     *
     * Auf `cloudsrv24` gemessen: **elf** Zeilen im Agenten, **vier** in der
     * transienten Unit, in der `apt-run all` einspielt. Der Betreiber sah elf,
     * drückte „Alle installieren" und bekam vier.
     *
     * > **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen und
     * > nicht eine.**
     *
     * Die Antwort ist nicht, dem Agenten seine Härtung zu nehmen, und auch
     * keine apt-Option: `Always-Include-Phased-Updates` greift gegen die
     * Chroot-Erkennung nicht, und `Never-Include-Phased-Updates` greift zwar,
     * verschiebt aber **beide** Seiten — es hielte phasenverzögerte Pakete auch
     * dann zurück, wenn Ubuntu diese Maschine ausgewählt hat.
     *
     * > **Ein Griff, der zwei Seiten zur Übereinstimmung bringt, indem er beide
     * > verschiebt, hat die Frage nicht beantwortet, sondern verlegt.**
     *
     * Gefragt wird deshalb dasselbe Skript, das auch einspielt, und über
     * denselben Weg. Damit stimmen die beiden Seiten **von Bauart wegen**
     * überein statt aus Versehen.
     *
     * @return array<string, string> Lauf => Ausgabe
     */
    public static function simulate(Context $context, string $runner, int $timeout = 120): array
    {
        $lauf = $context->runner->run('systemd-run', [
            '--quiet',

            // `--pipe` gibt uns die Ausgabe, `--wait` wartet auf das Ende, und
            // `--collect` räumt die Unit auch dann ab, wenn sie scheitert.
            '--pipe',
            '--wait',
            '--collect',
            '--setenv=DEBIAN_FRONTEND=noninteractive',
            $runner,
            'simulate',
        ], $timeout);

        if (! $lauf->successful()) {
            throw AgentException::execFailed(
                'Der Paketstand liess sich nicht ermitteln: '.$lauf->message(),
            );
        }

        return self::sections($lauf->stdout);
    }

    /**
     * Die beiden Läufe aus einer Ausgabe von `apt-run simulate` — die Naht.
     *
     * **Sie besteht auf genau zwei Abschnitten und rät nicht.** Eine Marke, die
     * fehlt, wäre sonst ein leerer Abschnitt — und ein leerer Abschnitt sieht
     * aus wie „nichts zu aktualisieren".
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
     * > tun".**
     *
     * @return array<string, string>
     *
     * @throws AgentException
     */
    public static function sections(string $stdout): array
    {
        $abschnitte = [];
        $offen = null;

        foreach (preg_split('/\R/', $stdout) ?: [] as $zeile) {
            if (str_starts_with($zeile, self::MARK)) {
                $offen = trim(substr($zeile, strlen(self::MARK)));
                $abschnitte[$offen] ??= [];

                continue;
            }

            // Was vor der ersten Marke steht, gehört niemandem. Es gibt dort
            // nichts zu lesen, und still mitzunehmen wäre schlimmer als es
            // wegzuwerfen.
            if ($offen === null) {
                continue;
            }

            $abschnitte[$offen][] = $zeile;
        }

        foreach (self::RUNS as $name) {
            if (! isset($abschnitte[$name])) {
                throw AgentException::execFailed(sprintf(
                    'Die Ausgabe von „apt-run simulate" führt den Abschnitt „%s" nicht. '.
                    'Gefunden: %s.',
                    $name,
                    $abschnitte === [] ? 'keinen' : implode(', ', array_keys($abschnitte)),
                ));
            }
        }

        return array_map(
            static fn (array $zeilen): string => implode("\n", $zeilen),
            $abschnitte,
        );
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
