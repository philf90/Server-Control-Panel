<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

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
 * **Gelesen werden zwei Dateien je Domain und nicht eine.** `access.log` und
 * `access.log.1`. Das ist die Antwort auf eine Messung vom 20. September 2026
 * auf `cloudsrv24`: `logrotate.timer` steht dort auf `OnCalendar=daily` mit
 * `AccuracySec=1h`. Die Rotation ist damit kein Zeitpunkt, sondern ein Fenster
 * von einer Stunde nach Mitternacht — und ein Nachtlauf, der in dieses Fenster
 * fällt, träfe die Datei mal vor und mal nach dem Umbenennen.
 *
 * > **Ein Lauf, der von einer Uhrzeit abhängt, die selbst ein Fenster ist,
 * > misst an manchen Tagen etwas anderes als an anderen.**
 *
 * Mit beiden Dateien ist die Reihenfolge gleichgültig: Vor der Rotation steht
 * der gestrige Tag vollständig in `access.log`, danach vollständig in `.1`.
 * Gruppiert wird ohnehin nach dem Tag **in der Zeile** ({@see AccessLog}), also
 * kommt in beiden Fällen dasselbe heraus. Die Folge für den Aufrufer steht in
 * seiner eigenen Pflicht: Er bekommt regelmässig auch Tage, die er schon hat,
 * und muss je Tag **überschreiben statt addieren**.
 *
 * **Was hier nicht gelesen wird, ist `.2.gz` und älter.** Das ist
 * Komprimiertes, und es ist bereits gezählt — es sei denn, der Nachtlauf ist
 * mehrere Tage ausgefallen. Dieser Fall ist eine Lücke und keine Panne: Er
 * gehört ins Panel, das seine Tage kennt, und nicht in eine Operation, die
 * jede Nacht denselben Weg geht.
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
            'totals' => self::totals($domains),
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
     * Eine Domain, aus ihren beiden Dateien zusammengezählt.
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

        foreach ([Site::ACCESS_LOG, Site::ROTATED_ACCESS_LOG] as $name) {
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

            foreach ($zahl['days'] as $tag => $werte) {
                $tage[$tag] ??= ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0];
                $tage[$tag]['requests'] += $werte['requests'];
                $tage[$tag]['sent'] += $werte['sent'];
                $tage[$tag]['received'] += $werte['received'];
                $tage[$tag]['errors'] += $werte['errors'];
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
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, int>
     */
    private static function totals(array $domains): array
    {
        $summe = ['domains' => count($domains), 'files' => 0, 'lines' => 0, 'parsed' => 0, 'legacy' => 0, 'unreadable' => 0];

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
