<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\Web\AccessLog;

/**
 * Die Zugriffsprotokolle aller Abonnements — gezählt, in einem Aufruf.
 *
 * **Ein Aufruf für alle, wie bei {@see SubscriptionUsage}, und aus demselben
 * Grund.** Ein Aufruf je Abonnement wäre bei hundert Abonnements hundert
 * Vorgänge im Protokoll für eine Messung, die niemand ausgelöst hat. Diese
 * Operation braucht deshalb **keine Argumente** — es gibt nichts auszuwählen.
 *
 * **Welche Domains es gibt, weiss der Agent nicht — er findet sie.** Die
 * Domainliste steht in der Datenbank des Panels, und die kennt er nicht. Er
 * sieht im Verzeichnis nach: unter {@see Site::logsRoot()} liegt je Domain
 * eines. Das ist kein Notbehelf, sondern richtiger als eine übergebene Liste:
 * Gezählt wird, was wirklich dasteht, und nicht, was das Panel dort vermutet.
 *
 * **Gelesen werden drei Dateien je Domain**: `access.log`, `access.log.1` und
 * das gepackte `access.log.2.gz`. Der Anlass ist eine Messung vom
 * 20. September 2026 auf `cloudsrv24`: `logrotate.timer` steht dort auf
 * `OnCalendar=daily` mit `AccuracySec=1h`. Die Rotation ist damit kein
 * Zeitpunkt, sondern ein Fenster von einer Stunde nach Mitternacht — und ein
 * Nachtlauf, der in dieses Fenster fällt, trifft die Datei mal vor und mal
 * nach dem Umbenennen.
 *
 * > **Ein Lauf, der von einer Uhrzeit abhängt, die selbst ein Fenster ist,
 * > misst an manchen Tagen etwas anderes als an anderen.**
 *
 * **Bis zum 24. September 2026 waren es zwei Dateien**, und hier stand, damit
 * sei die Reihenfolge gleichgültig: „Vor der Rotation steht der gestrige Tag
 * vollständig in `access.log`, danach vollständig in `.1`." Das gilt nur für
 * eine Rotation um Punkt Mitternacht. Was ein Tag bis zu seiner Rotation
 * schreibt — sein **Kopf** —, steht in der Datei des Vortags, und nach der
 * nächsten Rotation heisst sie `.2.gz`. Nach der Rotation gezählt, fehlte dem
 * Tag sein Anfang; davor gezählt, überschrieb ihn die nächste Nacht mit der
 * unvollständigen Sicht (`docs/134 §0` Punkt 2, nachgebaut in
 * `tests/tageswechsel-nachbauen.sh`).
 *
 * > **Wer nach dem Tag in der Zeile gruppiert, muss jede Datei lesen, in der
 * > dieser Tag stehen kann.**
 *
 * **Mit drei Dateien steht der gestrige Tag vollständig da, vor wie nach der
 * Rotation** — gruppiert wird nach dem Tag **in der Zeile** ({@see AccessLog}).
 * Die andere Hälfte der Behebung gehört dem Aufrufer: Er legt nur den Vortag
 * ab. Ältere Tage stehen hier auch drin, aber nicht mehr ganz — ihr Kopf ist
 * eine Datei weiter gewandert —, und eine Sicht auf sie überschriebe eine
 * vollständige Zahl mit einer halben.
 *
 * **Was hier nicht gelesen wird, ist `.3.gz` und älter.** Der Vortag steht nie
 * darin, solange in einer Nacht nur einmal rotiert wird. Läuft der Zähllauf
 * einen ganzen Tag lang nicht — auch nicht nachgeholt über `Persistent=true`
 * —, bekommt dessen Vortag keine Zahl: eine Lücke und keine halbe Zahl, nach
 * `docs/129 §5` („Ein Tag ohne Zahlen ist ehrlicher als ein Tag mit halben").
 *
 * **Was sie nicht unterscheiden kann.** Eine Datei, die dasteht und sich nicht
 * öffnen lässt, kommt bei {@see AccessLog::countFile()} als lauter Nullen
 * heraus — genau wie eine leere. Der Agent läuft als root, also braucht es
 * dafür ein Attribut oder eine ACL; gemessen ist der Fall nicht, und genau
 * deshalb steht hier kein Zähler dafür:
 *
 * > **Ein Wächter für einen Fall, den niemand herstellen kann, war nie rot —
 * > und ein Wächter, der nie rot war, ist keiner.**
 *
 * Wer den Fall herstellen kann, baut den Zähler und den Bruch dazu. Bis dahin
 * ist die Grenze hier benannt und nicht in einem Kopf.
 *
 * **Eine Ursache davon ist seit dem 24. September 2026 herstellbar, und sie
 * bekommt keinen Zähler, sondern eine Weigerung:** ein PHP ohne den Datenstrom
 * von zlib. Er trifft nicht eine Datei, sondern die gepackte jeder Domain, und
 * gezählt würde überall halb ({@see self::overRoot()}).
 *
 * Nicht verändernd — sie liest.
 */
final class WebAccessCount implements Op
{
    /** Dieselbe Wurzel wie beim Anlegen. Sie steht hier und kommt nicht von aussen. */
    public const VHOSTS = SubscriptionProvision::VHOSTS;

    /**
     * Wie lange gezählt werden darf, bevor der Rest liegen bleibt.
     *
     * **Warum es überhaupt ein Budget gibt.** {@see Client}
     * gibt nach 300 s auf. Eine Operation, die darüber läuft, liefert dem
     * Panel **nichts** — auch nicht die Domains, die sie längst gezählt hat.
     * Das Budget macht aus „alles oder nichts" ein „das meiste, und es sagt,
     * was fehlt".
     *
     * **Und was es nicht kann, steht hier und nicht im Nachhinein.** Geprüft
     * wird zwischen zwei Domains, nicht innerhalb einer Datei. Eine einzelne
     * sehr grosse Datei läuft darüber hinaus, und zwar so weit, wie sie eben
     * braucht. Ein Budget, das mitten in einer Datei abbräche, lieferte eine
     * halbe Zahl — und eine halbe Zahl ist schlimmer als keine.
     */
    public const BUDGET_SECONDS = 120;

    /** Weniger als das ist kein Budget, mehr als das überlebt der Aufruf nicht. */
    public const BUDGET_MIN = 5;

    public const BUDGET_MAX = 240;

    public static function name(): string
    {
        return 'web.access.count';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $budget = $this->budget($args['budget_seconds'] ?? null);
        $ende = microtime(true) + $budget;
        $begonnen = microtime(true);

        $context->progress(5, 'Abonnements suchen');

        // **Die Wurzel kommt aus der Konstante und niemals aus `$args`.** Eine
        // Operation, die sich sagen liesse, wo sie lesen soll, wäre ein Leser
        // für beliebige Dateien mit Systemrechten — und genau das ist die
        // erste Grenze.
        $zaehlung = self::overRoot(self::VHOSTS, $ende, static function (int $anteil, string $text) use ($context): void {
            $context->progress(5 + (int) (90 * $anteil / 100), $text);
        });

        $context->progress(100, 'fertig');

        return array_merge($zaehlung, [
            'root' => self::VHOSTS,
            'budget_seconds' => $budget,
            'duration_ms' => (int) round((microtime(true) - $begonnen) * 1000),
        ]);
    }

    /**
     * Die eigentliche Arbeit, über einer angegebenen Wurzel.
     *
     * **Öffentlich, damit sie geprüft werden kann — und trotzdem kein Loch.**
     * {@see execute()} ruft sie ausschliesslich mit {@see VHOSTS}; kein
     * Argument des Aufrufers erreicht diesen Parameter. Der Prüfstand darf eine
     * eigene Wurzel setzen, das Panel nicht.
     *
     * @param  ?callable(int, string): void  $progress
     * @return array{domains: list<array<string, mixed>>, pending: list<array<string, string>>, totals: array<string, int>}
     */
    public static function overRoot(string $root, float $deadline, ?callable $progress = null): array
    {
        /*
         * **Ohne den Datenstrom von zlib wird gar nicht gezählt statt halb.**
         * Fehlt er, lässt sich `access.log.2.gz` nicht öffnen, und
         * {@see AccessLog::countFile()} gibt dafür lauter Nullen — wie für eine
         * leere Datei. Jedem Vortag fehlte dann wieder sein Kopf, und keine
         * Zahl sagte es. Gemessen am 24. September 2026 mit abgemeldetem
         * Datenstrom: null Zeilen, null unlesbare, und daneben nur zwei
         * Warnungen von PHP.
         *
         * In `php8.4-cli` ist zlib eingebaut (`PackagedExtensionTest`). Gefragt
         * wird trotzdem, weil `/opt/srvpanel/bin/php` ein anderes PHP starten
         * kann — und dann wird aus der stillen Lücke ein Fehlschlag des
         * Nachtlaufs statt einer halben Zahl.
         *
         * > **Ein Datenstrom, den es nicht gibt, macht aus einer Datei eine
         * > leere — und eine leere Datei ist kein Fehler.**
         */
        if (! in_array('compress.zlib', stream_get_wrappers(), true)) {
            throw new AgentException(
                AgentException::INTERNAL,
                'Dem PHP des Agenten fehlt zlib: access.log.2.gz lässt sich nicht lesen, und jedem Tag fehlte sein Kopf. Gezählt wird deshalb nichts.',
            );
        }

        $abonnements = self::directories($root);
        $domains = [];
        $offen = [];

        $gesamt = max(1, count($abonnements));
        $nummer = 0;

        foreach ($abonnements as $abonnement) {
            $nummer++;

            if ($progress !== null) {
                $progress(
                    (int) (100 * $nummer / $gesamt),
                    sprintf('%s (%d/%d)', $abonnement, $nummer, count($abonnements)),
                );
            }

            foreach (self::directories(Site::logsRootIn($root, $abonnement)) as $domain) {
                if (microtime(true) >= $deadline) {
                    $offen[] = ['subscription' => $abonnement, 'domain' => $domain];

                    continue;
                }

                $domains[] = self::domain($root, $abonnement, $domain);
            }
        }

        return [
            'domains' => $domains,
            'pending' => $offen,
            'totals' => self::totals($domains, $offen),
        ];
    }

    /**
     * Das Budget aus den Argumenten — oder die Vorgabe.
     *
     * Geklammert und nicht geglaubt: Eine Null käme sonst als Budget durch und
     * liesse jede Domain offen, ein zu grosser Wert liefe in das Zeitlimit des
     * Aufrufers, das dieses Budget gerade verhindern soll.
     */
    private function budget(mixed $wert): int
    {
        if (! is_int($wert) && ! (is_string($wert) && ctype_digit($wert))) {
            return self::BUDGET_SECONDS;
        }

        return max(self::BUDGET_MIN, min(self::BUDGET_MAX, (int) $wert));
    }

    /**
     * Eine Domain, aus ihren drei Dateien zusammengezählt.
     *
     * @return array<string, mixed>
     */
    private static function domain(string $root, string $abonnement, string $domain): array
    {
        $verzeichnis = Site::logsRootIn($root, $abonnement).'/'.$domain;

        $tage = [];
        $zeilen = 0;
        $gedeutet = 0;
        $alt = 0;
        $unrat = 0;
        $dateien = 0;

        foreach ([Site::ACCESS_LOG, Site::ROTATED_ACCESS_LOG, Site::SECOND_ROTATED_ACCESS_LOG] as $name) {
            $pfad = $verzeichnis.'/'.$name;

            if (! is_file($pfad)) {
                continue;
            }

            $dateien++;
            $zahl = AccessLog::countFile($pfad);

            $zeilen += $zahl['lines'];
            $gedeutet += $zahl['parsed'];
            $alt += $zahl['legacy'];
            $unrat += $zahl['unreadable'];

            /*
             * **`legacy` geht mit, und zwar je Tag.** Es fiel hier bis zum
             * 20. September 2026 unter den Tisch: Die Zusammenführung legte
             * vier Schlüssel an und addierte vier, während {@see AccessLog}
             * fünf lieferte. Der Prüfstand blieb grün, weil er dieselbe
             * verkürzte Form erwartete.
             *
             * > **Zwei Stellen, die sich auf eine Form einigen, ohne dass eine
             * > dritte sie nachzählt, einigen sich irgendwann auf die falsche.**
             *
             * Ohne diesen Wert kann der Nachtlauf die Regel aus `docs/129 §5`
             * nicht durchsetzen: Ein Tag, in dem eine Zeile des alten
             * Zeitalters steht, wird nicht gezählt.
             */
            foreach ($zahl['days'] as $tag => $werte) {
                $tage[$tag] ??= ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0, 'legacy' => 0];

                foreach (['requests', 'sent', 'received', 'errors', 'legacy'] as $feld) {
                    $tage[$tag][$feld] += $werte[$feld];
                }
            }
        }

        ksort($tage);

        return [
            'subscription' => $abonnement,
            'domain' => $domain,
            'files' => $dateien,
            'lines' => $zeilen,
            'parsed' => $gedeutet,
            'legacy' => $alt,
            'unreadable' => $unrat,
            'days' => $tage,
        ];
    }

    /**
     * Die Summen über alle Domains.
     *
     * **Sie stehen da, damit ein Nachtlauf eine Zeile protokollieren kann, die
     * etwas sagt.** „6 Domains gezählt" ist keine Auskunft; „6 Domains, 7
     * Zeilen, davon 6 aus dem alten Zeitalter" ist eine — und an dem Tag, an
     * dem `legacy` nicht mehr sinkt, weiss der Betreiber, dass irgendwo noch
     * ein Server-Block das alte Format schreibt.
     *
     * **Und `pending` steht mit darin, nicht nur daneben.** Baut ein Nachtlauf
     * seine Zeile aus dieser Summe allein, meldete er sonst „6 Domains" —
     * auch dann, wenn vierzig weitere ungezählt liegen geblieben sind. Das ist
     * genau die Art Null, die dieses Projekt immer wieder einfängt: keine
     * Fehlermeldung, nur eine Zahl, die nach Vollständigkeit aussieht.
     *
     * @param  list<array<string, mixed>>  $domains
     * @param  list<array<string, string>>  $offen
     * @return array<string, int>
     */
    private static function totals(array $domains, array $offen): array
    {
        $summe = ['domains' => count($domains), 'pending' => count($offen), 'files' => 0, 'lines' => 0, 'parsed' => 0, 'legacy' => 0, 'unreadable' => 0];

        foreach ($domains as $eintrag) {
            foreach (['files', 'lines', 'parsed', 'legacy', 'unreadable'] as $feld) {
                $wert = $eintrag[$feld] ?? 0;
                $summe[$feld] += is_int($wert) ? $wert : 0;
            }
        }

        return $summe;
    }

    /**
     * Die Unterverzeichnisse eines Verzeichnisses, sortiert.
     *
     * **Ein Punkt am Anfang fliegt raus, und das ist kein Formalismus.** Das
     * Messmittel `tests/plattenkurve-messen.sh` legt seinen Prüfkörper als
     * `.plattenkurve-probe.<pid>` genau hier ab. Ohne diese Zeile stünde er
     * als Abonnement in der Auswertung — ein Abonnement, das es nicht gibt,
     * mit null Domains darin.
     *
     * @return list<string>
     */
    private static function directories(string $pfad): array
    {
        if (! is_dir($pfad)) {
            return [];
        }

        $eintraege = scandir($pfad);

        if ($eintraege === false) {
            return [];
        }

        $verzeichnisse = [];

        foreach ($eintraege as $eintrag) {
            if (str_starts_with($eintrag, '.')) {
                continue;
            }

            if (! is_dir($pfad.'/'.$eintrag)) {
                continue;
            }

            $verzeichnisse[] = $eintrag;
        }

        sort($verzeichnisse);

        return $verzeichnisse;
    }
}
