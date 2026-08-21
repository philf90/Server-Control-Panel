<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ein Verzeichnis hat einen Eigentümer, und zwar genau einen.
 *
 * ## Der Fund, der diesen Wächter ausgelöst hat
 *
 * `docs/67`, Befund 1. Nach dem Update auf `v0.6.0-rc.21` gab jede Seite einen
 * 500er: Die Anwendung konnte ihr eigenes Protokoll nicht öffnen.
 *
 *     There is no existing directory at ".../storage/logs"
 *     and it could not be created: Permission denied
 *
 * Fünf Stellen im Projekt sagen, `/var/lib/srvpanel` sei
 * `0750 srvpanel:srvpanel` — `nfpm.yaml`, `postinstall.sh`, `testbed.sh` und
 * `PackagingTest`. Eine Zeile in `srvpanel-agentd.service` sagte etwas anderes:
 *
 *     StateDirectory=srvpanel
 *
 * Das heisst für systemd „dieses Verzeichnis gehört diesem Dienst", und systemd
 * zieht dann bei **jedem Start** den Modus auf `StateDirectoryMode` nach —
 * Vorgabe `0755`. Der Dienst läuft als root, das Verzeichnis gehört dem Panel.
 *
 * Gemessen auf `cloudsrv24`: vor `systemctl restart srvpanel-agentd` stand es
 * auf `750`, danach auf `755`.
 *
 * > **Ein Verzeichnis, dessen Rechte an zwei Stellen festgelegt werden, hat die
 * > Rechte der Stelle, die zuletzt läuft.**
 *
 * ## Warum kein Test das gefunden hat
 *
 * `testbed.sh` prüfte den Eigentümer **nach** `apt-get remove` — also zu dem
 * einen Zeitpunkt, an dem der Agent nicht mehr läuft. Vier grüne
 * Installationsläufe auf vier Plattformen haben den Fehler durchgelassen.
 *
 * > **Eine Prüfung, die zum falschen Zeitpunkt misst, misst einen Zustand, den
 * > es im Betrieb nie gibt.**
 *
 * Dieser Wächter braucht keinen Zeitpunkt: Er vergleicht die **Absichten**
 * miteinander, und zwei widersprüchliche Absichten sind schon vor dem ersten
 * Start ein Fehler.
 */
final class ServiceDirectoryTest extends TestCase
{
    /**
     * Was systemd verwaltet, und wohin es zeigt.
     *
     * @var array<string,string>
     */
    private const KINDS = [
        'RuntimeDirectory' => '/run/',
        'StateDirectory' => '/var/lib/',
        'LogsDirectory' => '/var/log/',
        'CacheDirectory' => '/var/cache/',
        'ConfigurationDirectory' => '/etc/',
    ];

    /** Was systemd nimmt, wenn die Unit nichts anderes sagt. */
    private const DEFAULT_MODE = '0755';

    private const DEFAULT_OWNER = 'root';

    /**
     * Keine Unit beansprucht ein Verzeichnis, das die Paketierung anders meint.
     */
    public function test_no_unit_claims_a_directory_the_packaging_owns_differently(): void
    {
        $gepackt = $this->packagedDirectories();
        $ansprueche = $this->claims();
        $streit = [];

        foreach ($ansprueche as $anspruch) {
            $pfad = $anspruch['pfad'];

            if (! isset($gepackt[$pfad])) {
                continue;
            }

            $soll = $gepackt[$pfad];

            if ($soll['mode'] !== $anspruch['mode'] || $soll['owner'] !== $anspruch['owner']) {
                $streit[] = sprintf(
                    '%s beansprucht %s als `%s` (%s %s), die Paketierung liefert es als %s %s (%s)',
                    $anspruch['unit'],
                    $pfad,
                    $anspruch['kind'],
                    $anspruch['mode'],
                    $anspruch['owner'],
                    $soll['mode'],
                    $soll['owner'],
                    $soll['quelle'],
                );
            }
        }

        /*
         * **Zwei Untergrenzen, je eine für jede Seite des Vergleichs.** Fällt
         * eine der beiden Auslesen aus, vergleicht dieser Wächter eine leere
         * Menge mit irgendetwas und meldet „alles in Ordnung" — genau die Form
         * von Grün, gegen die er gebaut ist.
         */
        $this->assertGreaterThanOrEqual(1, count($ansprueche), sprintf(
            'Keine einzige %s-Angabe in packaging/systemd gefunden — dann prueft dieser Waechter '.
            'nichts.',
            implode('/', array_keys(self::KINDS)),
        ));

        /*
         * **Je Quelle eine Zahl, und nicht eine über beide.** Der erste Wurf
         * zählte die vereinigte Menge — und war grün, als der Gegenbruch die
         * Auslese von `nfpm.yaml` zerstörte: `postinstall.sh` lieferte allein
         * genug Einträge, um die Untergrenze zu halten. Eine Quelle war blind,
         * und niemand hat es gemerkt.
         *
         * > **Eine Untergrenze über zwei Quellen fängt den Ausfall einer von
         * > beiden nicht — die andere zahlt für sie mit.**
         */
        foreach ($this->countedSources() as $quelle => $anzahl) {
            $this->assertGreaterThanOrEqual(1, $anzahl, sprintf(
                'Aus %s liest dieser Waechter kein einziges Verzeichnis mehr — dann prueft er '.
                'diese Quelle nicht, und die andere deckt die Zahl allein ab.',
                $quelle,
            ));
        }

        $this->assertSame([], $streit, sprintf(
            "Hier legen zwei Stellen dieselben Rechte fest, und sie sind sich uneinig:\n\n  %s\n\n".
            'systemd zieht sein `%sDirectoryMode` bei **jedem Start** des Dienstes nach. Die '.
            'Paketierung verliert damit jedes Mal, und zwar lautlos: Der Fehler zeigt sich erst '.
            'beim naechsten Neustart (docs/67, Befund 1). Entweder gehoert das Verzeichnis dem '.
            'Dienst — dann stimmt die Paketierung sich darauf ab —, oder die Direktive gehoert '.
            'aus der Unit.',
            implode("\n  ", $streit),
            'State',
        ));
    }

    /**
     * Der Prüfstand misst die Rechte, während die Dienste laufen.
     *
     * **Die zweite Hälfte von Befund 1.** Die Prüfung stand **nach**
     * `apt-get remove` — also zu dem einen Zeitpunkt, an dem der Agent nicht
     * mehr läuft und nie wieder startet. Genau dort war `0750 srvpanel:srvpanel`
     * zu sehen, und im Betrieb nie. Vier grüne Installationsläufe auf vier
     * Plattformen haben den Fehler so durchgelassen.
     *
     * > **Eine Prüfung, die zum falschen Zeitpunkt misst, misst einen Zustand,
     * > den es im Betrieb nie gibt.**
     *
     * Geprüft wird deshalb beides: dass **vor** dem Entfernen gemessen wird,
     * und dass ein Neustart des Agenten dazwischen liegt — denn das war der
     * Griff, der es umwarf.
     */
    public function test_the_testbed_measures_while_the_services_run(): void
    {
        /*
         * **Ohne die Kommentare, und das war beim ersten Anlauf rot.** In
         * `testbed.sh` steht seit der Behebung wortwörtlich, dass die Prüfung
         * früher *nach* `apt-get remove` stand — und `strpos` fand genau diese
         * Erklärung, lange vor dem Befehl. Der Wächter meldete die behobene
         * Reihenfolge als kaputt.
         *
         * > **Ein Wächter, der eine Datei liest, liest auch, was jemand über
         * > sie geschrieben hat.**
         */
        $testbed = $this->withoutComments(
            (string) file_get_contents($this->root().'/packaging/testbed.sh')
        );

        $entfernen = strpos($testbed, 'apt-get remove');
        /*
         * **Die letzte Messung, nicht die erste.** Der erste Anlauf las
         * `strpos` — und war grün, als der Gegenbruch eine der beiden
         * Messungen hinter das Entfernen schob: Die andere stand noch davor
         * und deckte sie zu. Dieselbe Form wie die Untergrenze über zwei
         * Quellen eine Methode weiter unten.
         *
         * > **Eine Prüfung über das erste Vorkommen sagt nichts über das
         * > zweite — und das zweite ist das, das umzieht.**
         */
        $messung = strrpos($testbed, 'stat -c \'%a %U:%G\' /var/lib/srvpanel');
        $neustart = strpos($testbed, 'systemctl restart srvpanel-agentd');

        $this->assertIsInt($entfernen, 'Im Pruefstand wird nichts mehr entfernt — dann prueft dieser Waechter die Reihenfolge von nichts.');

        $this->assertIsInt($messung, 'Der Pruefstand misst die Rechte an /var/lib/srvpanel nicht mehr. Ohne diese Messung '.
            'faellt ein Verzeichnis, das zwei Herren hat, erst auf dem Server auf.',
        );

        $this->assertIsInt($neustart, 'Der Pruefstand startet den Agenten nicht mehr neu. Genau dieser Griff hat den Modus '.
            'umgeworfen — ohne ihn misst der Lauf den Zustand vor dem ersten Neustart.',
        );

        $this->assertLessThan($entfernen, $messung, 'Die Messung der Rechte steht hinter `apt-get remove`. Dann misst sie einen Server '.
            'ohne Dienste — und der Zustand, den ein Kunde sieht, bleibt ungeprueft.',
        );

        $this->assertLessThan($entfernen, $neustart, 'Der Neustart des Agenten steht hinter `apt-get remove` — dann startet er gar nicht '.
            'mehr, und die Messung danach sagt nichts.',
        );
    }

    /**
     * Wie viele Verzeichnisse jede der beiden Quellen hergibt.
     *
     * @return array<string,int>
     */
    private function countedSources(): array
    {
        $zahlen = ['nfpm.yaml' => 0, 'postinstall.sh' => 0];

        foreach ($this->packagedDirectories(true) as $eintrag) {
            $zahlen[$eintrag['quelle']]++;
        }

        return $zahlen;
    }

    /**
     * Was die Units für sich beanspruchen.
     *
     * @return list<array{unit: string, kind: string, pfad: string, mode: string, owner: string}>
     */
    private function claims(): array
    {
        $gefunden = [];

        foreach (glob($this->root().'/packaging/systemd/*.service') ?: [] as $datei) {
            $quelle = $this->withoutComments((string) file_get_contents($datei));
            $unit = basename($datei);

            $owner = preg_match('/^User=(\S+)/m', $quelle, $u) === 1 ? $u[1] : self::DEFAULT_OWNER;

            foreach (self::KINDS as $kind => $wurzel) {
                if (preg_match('/^'.$kind.'=(\S+)/m', $quelle, $t) !== 1) {
                    continue;
                }

                $mode = preg_match('/^'.$kind.'Mode=(\S+)/m', $quelle, $m) === 1
                    ? $this->normalise($m[1])
                    : self::DEFAULT_MODE;

                $gefunden[] = [
                    'unit' => $unit,
                    'kind' => $kind,
                    'pfad' => $wurzel.$t[1],
                    'mode' => $mode,
                    'owner' => $owner,
                ];
            }
        }

        return $gefunden;
    }

    /**
     * Was die Paketierung über Verzeichnisse sagt — aus beiden Quellen.
     *
     * `postinstall.sh` steht **hinter** `nfpm.yaml`: Es läuft später und ist
     * damit die Stelle, die im Zweifel gilt.
     *
     * @return array<array-key,array{mode: string, owner: string, quelle: string}>
     */
    private function packagedDirectories(bool $alle = false): array
    {
        $gefunden = [];
        $liste = [];
        $nfpm = (string) file_get_contents($this->root().'/packaging/nfpm.yaml');

        preg_match_all(
            '/-\s+dst:\s*(\S+)\s*\n\s*type:\s*dir\s*\n\s*file_info:\s*\n\s*mode:\s*(\S+)\s*\n\s*owner:\s*(\S+)/',
            $nfpm,
            $treffer,
            PREG_SET_ORDER,
        );

        foreach ($treffer as $t) {
            $gefunden[$t[1]] = $liste[] = [
                'mode' => $this->normalise($t[2]),
                'owner' => $t[3],
                'quelle' => 'nfpm.yaml',
            ];
        }

        $postinst = $this->withoutComments(
            (string) file_get_contents($this->root().'/packaging/scripts/postinstall.sh')
        );

        preg_match_all(
            '/install -d -o (\S+) -g \S+ -m (\S+) (\S+)/',
            $postinst,
            $treffer,
            PREG_SET_ORDER,
        );

        foreach ($treffer as $t) {
            $gefunden[$t[3]] = $liste[] = [
                'mode' => $this->normalise($t[2]),
                'owner' => $t[1],
                'quelle' => 'postinstall.sh',
            ];
        }

        // `$alle` zählt beide Quellen einzeln; `$gefunden` ist die Sicht für
        // den Vergleich, in der postinstall.sh nfpm.yaml überschreibt.
        return $alle ? $liste : $gefunden;
    }

    /** `750` und `0750` sind dasselbe — verglichen wird vierstellig. */
    private function normalise(string $mode): string
    {
        return str_pad(ltrim($mode, '0') === '' ? '0' : ltrim($mode, '0'), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Eine Datei ohne ihre Kommentarzeilen.
     *
     * **Sonst zählt die Erklärung mit.** In der Unit steht seit der Behebung
     * wortwörtlich, welche Direktiven dort *nicht* mehr stehen — und ein
     * Ausdruck, der die Datei roh liest, findet genau die und meldet den
     * behobenen Fehler als bestehend.
     *
     * > **Ein Wächter, der eine Datei liest, liest auch, was jemand über sie
     * > geschrieben hat.**
     */
    private function withoutComments(string $quelle): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $quelle);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
