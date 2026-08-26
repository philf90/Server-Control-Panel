<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Was `apt-get -s` sagt, als Auskunft statt als Text.
 *
 * **Getrennt vom Aufruf, und zwar aus demselben Grund wie {@see Apt::of()}.**
 * `Runner` und `Context` sind `final`, es gibt also keine Attrappe; ohne diese
 * Naht wäre der Leser nur über einen echten Server prüfbar, und das heisst: gar
 * nicht.
 *
 * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der Weg
 * > dahinter auch nicht.**
 *
 * `InstLineTest` baut seine Prüfkörper deshalb Zeile für Zeile selbst, so wie
 * `ArchiveDepthTest` seine Archive baut. Ein Prüfkörper aus `apt-get -s` auf
 * der Maschine, auf der gerade gemessen wird, enthält genau die Fälle **nicht**,
 * an denen der Leser bricht — gemessen am 26. August 2026: Auf `debian:12` gibt
 * es keine einzige `Inst`-Zeile, weil das Abbild vollständig aktuell ist.
 *
 * **Die Sprache der Ausgabe ist zugesichert.** `Runner::ENVIRONMENT` setzt
 * `LC_ALL=C` und `LANG=C`; „The following packages have been kept back" steht
 * deshalb englisch da und nicht in der Sprache des Servers. Ohne diese Zusage
 * wäre jede Textsuche hier geraten.
 */
final class Packages
{
    /**
     * Der Kopf einer `Inst`-Zeile.
     *
     * **Die eckige Klammer nach dem Namen ist die alte Fassung — und sie
     * fehlt.** Bei einer Neuinstallation steht dort nichts:
     *
     *     Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])
     *     Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])
     *
     * Wer „die eckige Klammer" nimmt, greift bei der zweiten Zeile die
     * **Architektur** — die steht ebenfalls in eckigen Klammern, nur am Ende
     * und innerhalb der runden. Beide Formen kommen in einem `dist-upgrade`
     * nebeneinander vor.
     *
     * **Und hinter der schliessenden runden Klammer steht noch etwas.** apt
     * hängt dort an, welche Pakete diese Zeile ausgelöst haben — als eine oder
     * mehrere eckige Gruppen, und manchmal als leere:
     *
     *     … [amd64]) []
     *     … [amd64]) [perl:amd64 ]
     *     … [amd64]) [libpam-modules:amd64 on libpam-modules-bin:amd64] [libpam-modules:amd64 ]
     *
     * Der Ausdruck **nimmt sie hin und liest sie nicht**: Sie beantwortet
     * „warum wird das aktualisiert", und danach fragt keine Anzeige dieser
     * Stufe. Ein Feld, das hier entstünde und das niemand liest, wäre von
     * aussen nicht von einem zu unterscheiden, das es nicht gibt.
     *
     * **Ohne diese Duldung verschweigt der Leser die Hälfte.** Ein `$` hinter
     * der runden Klammer wirft jede Zeile mit Anhang weg, und zwar wortlos —
     * gemessen am 26. August 2026 gegen `apt-get -s dist-upgrade` auf diesem
     * Container: 145 `Inst`-Zeilen, davon **56 mit Anhang**, gelesen wurden 89.
     * Die Zahl sah nach einem Ergebnis aus.
     */
    private const INST = '/^Inst (?<name>[^\s\[(]+)(?: \[(?<old>[^\]]*)\])? \((?<body>.*)\)(?: \[[^\]]*\])*$/D';

    /**
     * Der Rumpf: neue Fassung, Herkünfte, Architektur.
     *
     * Beide hinteren Teile sind wahlfrei. Ein Paket aus einer lokalen Datei hat
     * keine Herkunft, und ältere apt-Fassungen lassen die Architektur weg.
     */
    private const BODY = '/^(?<new>\S+)(?: (?<origins>.*?))?(?: \[(?<architecture>[^\]]+)\])?$/D';

    /** `Remv sl [3.03+dfsg2-8]` — der Name, die Fassung interessiert hier nicht. */
    private const REMV = '/^Remv (?<name>[^\s\[(]+)/D';

    /**
     * Eine `Inst`-Zeile lesen. `null`, wenn es keine ist.
     *
     * @return null|array{name: string, old: null|string, new: string, origins: list<string>, architecture: null|string, security: bool}
     */
    public static function inst(string $line): ?array
    {
        if (preg_match(self::INST, rtrim($line, "\r\n"), $kopf) !== 1) {
            return null;
        }

        if (preg_match(self::BODY, $kopf['body'], $rumpf) !== 1) {
            return null;
        }

        $origins = self::origins($rumpf['origins'] ?? '');

        return [
            'name' => $kopf['name'],

            // **`old` steht immer da, die beiden anderen nicht** — und der
            // Unterschied ist kein Geschmack, sondern PCRE: Eine
            // Gruppe, die nicht mitspielt, fehlt nur dann im Ergebnis, wenn
            // nach ihr keine mitspielende mehr kommt. Hinter `old` steht
            // `body`, also ist `old` bei einer Neuinstallation `''` und nicht
            // fort; `architecture` steht am Ende und fehlt dann wirklich.
            // Gemessen am 26. August 2026, alle drei Gruppen einzeln.
            //
            // Wer hier das `??` beim einen streicht und beim anderen
            // stehenlässt, hat nicht aufgeräumt, sondern die Messung
            // rückgängig gemacht.
            'old' => $kopf['old'] === '' ? null : $kopf['old'],
            'new' => $rumpf['new'],
            'origins' => $origins,
            'architecture' => ($rumpf['architecture'] ?? '') === '' ? null : $rumpf['architecture'],
            'security' => self::security($origins),
        ];
    }

    /** Der Name aus einer `Remv`-Zeile. `null`, wenn es keine ist. */
    public static function remv(string $line): ?string
    {
        return preg_match(self::REMV, rtrim($line, "\r\n"), $treffer) === 1
            ? $treffer['name']
            : null;
    }

    /**
     * Die Herkünfte einer Zeile — eine **Liste** und kein Wert.
     *
     *     (5.34.0-3ubuntu1.8 Ubuntu:22.04/jammy-updates, Ubuntu:22.04/jammy-security [amd64])
     *
     * Gemessen auf Ubuntu 22.04 am 26. August 2026: Von zwei aktualisierbaren
     * Paketen trugen **beide** zwei Herkünfte.
     *
     * @return list<string>
     */
    public static function origins(string $roh): array
    {
        $teile = preg_split('/,\s*/', trim($roh)) ?: [];

        return array_values(array_filter($teile, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Ist das ein Sicherheitsupdate?
     *
     * **Irgendeine der Herkünfte, nicht die erste.** Ein Paket, das in
     * `noble-updates` **und** `noble-security` liegt, ist eines — und apt nennt
     * die Aktualisierungssuite zuerst. Gemessen auf diesem Container am
     * 26. August 2026: 124 von 145 aktualisierbaren Paketen tragen beide, in
     * dieser Reihenfolge. Wer die erste Herkunft nimmt, zählt sie alle als
     * gewöhnliche.
     *
     * **Geprüft wird am Ende und nicht irgendwo.** Der Anbieter steht vorn:
     *
     *     Ubuntu:24.04/noble-security          → ja
     *     Ubuntu:24.04/noble-updates           → nein
     *     Debian-Security:13/stable-security   → ja
     *     foo-security:1/stable                → nein
     *     Docker CE:noble                      → nein
     *
     * Ein `str_contains` hielte die vierte Zeile für ein Sicherheitsupdate —
     * ein Anbieter, der so heisst, sagt über die Suite nichts.
     *
     * **Hier stand bis zum 26. August eine Trennung am letzten Schrägstrich**,
     * begründet mit genau dieser vierten Zeile. Die Begründung war falsch: Die
     * Suite ist ein Suffix der Herkunft, `str_ends_with` kann über beiden also
     * gar nicht verschieden antworten. Aufgefallen ist es, weil der Bruch dazu
     * nicht gebissen hat — die fünf Zeilen oben sind gemessen und nicht
     * gedacht.
     *
     * > **Ein Eingriff, der nicht beisst, sagt entweder etwas über den Wächter
     * > oder etwas über die Regel.**
     *
     * @param  list<string>  $origins
     */
    public static function security(array $origins): bool
    {
        foreach ($origins as $origin) {
            if (str_ends_with($origin, '-security')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die beiden Überschriften, unter denen apt Zurückgehaltenes aufzählt.
     *
     * **Zwei Wortlaute für dieselbe Auskunft, und bis zum 26. August 2026
     * kannte dieser Leser nur einen** (`docs/86`, Befund 7). Auf `cloudsrv24`
     * standen sieben Pakete unter dem zweiten, und die Kachel meldete `0`.
     *
     * > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die
     * > Gewohnheit und nicht die Regel.**
     *
     * **Und sie sind trotzdem nicht dasselbe.** Ein Paket bleibt stehen, weil
     * ein Upgrade etwas entfernen müsste — dagegen hilft `dist-upgrade`, und
     * genau das tut der Knopf „Alle installieren". Ein phasenverzögertes bleibt
     * stehen, weil Ubuntu es stufenweise ausrollt; dagegen hilft **warten**.
     * Ohne den Unterschied drückt der Betreiber den Knopf, sieht dieselben
     * Namen wieder und ist zurück in dem Fall, für den es diese Stufe gibt.
     *
     * > **Zwei Zustände mit derselben Anzeige und verschiedener Abhilfe sind
     * > eine Anzeige zu wenig.**
     *
     * @var array<string, string> Satzstück => Grund
     */
    private const KEPT_BACK = [
        'have been kept back' => 'dependencies',
        'deferred due to phasing' => 'phasing',
    ];

    /**
     * Die zurückgehaltenen Pakete aus einer `upgrade`-Ausgabe.
     *
     * Die Namen stehen eingerückt unter ihrer Überschrift, mehrere je Zeile —
     * und **beide Abschnitte können nebeneinander stehen**, deshalb bricht
     * diese Schleife nicht ab, wenn einer zu Ende ist.
     *
     * > **Diese Zahl gehört in die Anzeige**, sonst behauptet sie
     * > Vollständigkeit, die sie nicht hat.
     *
     * @return list<array{name: string, reason: string}>
     */
    public static function keptBack(string $upgrade): array
    {
        $namen = [];
        $grund = null;

        foreach (preg_split('/\R/', $upgrade) ?: [] as $zeile) {
            $ueberschrift = null;

            foreach (self::KEPT_BACK as $satz => $art) {
                if (str_contains($zeile, $satz)) {
                    $ueberschrift = $art;

                    break;
                }
            }

            if ($ueberschrift !== null) {
                $grund = $ueberschrift;

                continue;
            }

            if ($grund === null) {
                continue;
            }

            /*
             * Die Liste endet an der ersten Zeile, die nicht eingerückt ist —
             * dort beginnt der nächste Abschnitt („The following packages will
             * be upgraded:"). Weitergelesen wird trotzdem: Der zweite
             * Abschnitt kann darunter noch kommen, und wer hier abbricht,
             * findet ihn nie.
             */
            if ($zeile === '' || ! str_starts_with($zeile, ' ')) {
                $grund = null;

                continue;
            }

            foreach (preg_split('/\s+/', trim($zeile)) ?: [] as $name) {
                if ($name !== '') {
                    $namen[] = ['name' => $name, 'reason' => $grund];
                }
            }
        }

        return $namen;
    }

    /**
     * Die ganze Auskunft aus beiden Läufen.
     *
     * **Zwei Läufe und nicht einer.** `dist-upgrade` sagt, was möglich ist;
     * `upgrade` sagt, was ohne Entfernen möglich ist. Die Differenz ist die
     * Zahl, die ein Betreiber sehen muss — und sie steht in keinem der beiden
     * Läufe allein.
     *
     * @return array{upgradable: list<array<string, mixed>>, removals: list<string>, held: list<array{name: string, reason: string}>, security: int, fresh: int}
     */
    public static function read(string $distUpgrade, string $upgrade): array
    {
        $upgradable = [];
        $removals = [];

        foreach (preg_split('/\R/', $distUpgrade) ?: [] as $zeile) {
            $inst = self::inst($zeile);

            if ($inst !== null) {
                $upgradable[] = $inst;

                continue;
            }

            $remv = self::remv($zeile);

            if ($remv !== null) {
                $removals[] = $remv;
            }
        }

        return [
            'upgradable' => $upgradable,
            'removals' => $removals,
            'held' => self::keptBack($upgrade),
            'security' => count(array_filter($upgradable, static fn (array $p): bool => $p['security'])),

            // Eine Neuinstallation, die ein Upgrade nach sich zieht — die
            // Zeile ohne alte Fassung. Sie getrennt zu zählen ist keine
            // Spielerei: Ein Betreiber, der „12 Aktualisierungen" liest und
            // hinterher drei neue Pakete auf der Platte hat, ist belogen
            // worden.
            'fresh' => count(array_filter($upgradable, static fn (array $p): bool => $p['old'] === null)),
        ];
    }
}
