<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutHashComments;

/**
 * Ein `srvpanel-*`-Unitname in einer Anweisung zeigt auf eine Unit, die es gibt.
 *
 * ## Der Anlass
 *
 * `docs/905` nannte an drei Stellen `srvpanel-agent`; die Unit heisst
 * `srvpanel-agentd.service`. Eine davon war **§6, ein Ausschlusskriterium** —
 * `systemctl stop srvpanel-agent` hält nichts an, und der Punkt hätte den
 * Zustand, den er prüfen soll, nie hergestellt.
 *
 * > **`systemctl is-active` meldet für eine Unit, die es nicht gibt,
 * > `inactive` — ununterscheidbar von einer, die angehalten ist.**
 *
 * Das ist die Fehlerklasse, die dieses Projekt am häufigsten trifft: eine
 * Zeichenkette, die auf etwas verweist, ohne dass ein Werkzeug den Bezug
 * prüft. {@see UnitCatalogTest} hält dieselbe Frage für den Katalog des
 * Panels; hier geht es um die Anweisungen, die ein Mensch abtippt.
 *
 * ## Warum nur `srvpanel-*`
 *
 * Weil die Vorschriften auch `nginx`, `php8.3-fpm` und `cron` nennen, und die
 * gehören nicht uns. Eine Liste fremder Units zu pflegen hiesse, die
 * Paketierung anderer Leute nachzubauen — und die Lücke, die wirklich
 * entsteht, ist die eigene Umbenennung.
 *
 * ## Warum nur Codeblöcke
 *
 * Weil derselbe Text daneben **erklärt**, dass es den Namen nicht gibt. Wer
 * roh liest, meldet den Absatz, der die Behebung beschreibt — derselbe Fall
 * wie beim Schritt „Oberfläche" der CI, der `resources/js` ohne Rücksicht auf
 * Kommentare liest.
 *
 * > **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
 * > Messung fälschlich rot.**
 */
final class UnitNameReachTest extends TestCase
{
    use WithoutHashComments;

    /** Wo die Units paketiert sind. */
    private const UNITS = 'packaging/systemd';

    /**
     * Jeder genannte Unitname ist paketiert.
     */
    public function test_every_named_unit_exists(): void
    {
        $vorhanden = $this->packagedUnits();

        $this->assertNotEmpty(
            $vorhanden,
            'Unter '.self::UNITS.' liegen keine Units — der Wächter misst nichts.',
        );

        $fehler = [];
        $gesehen = 0;

        foreach ($this->instructions() as $ort => $text) {
            /*
             * `systemctl` mit beliebig vielen Schaltern davor, dann ein Verb,
             * dann ein oder mehrere Namen. Gesucht werden die Namen und nicht
             * das Verb: `stop`, `is-active`, `start`, `enable` — die Liste
             * wäre die nächste, die jemand erweitert und vergisst.
             */
            preg_match_all('/\bsystemctl\b([^\n|;&]*)/', $text, $aufrufe);

            foreach ($aufrufe[1] as $rest) {
                preg_match_all('/(?<![\w.-])(srvpanel[\w.-]*)/', $rest, $namen);

                foreach ($namen[1] as $name) {
                    $gesehen++;

                    if (! $this->resolves($name, $vorhanden)) {
                        $fehler[] = sprintf('%s: „%s"', $ort, $name);
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $gesehen,
            'Kein einziger `systemctl`-Aufruf mit einer eigenen Unit gefunden — der Ausdruck greift nicht mehr.',
        );

        $this->assertSame([], array_unique($fehler), sprintf(
            "Diese Anweisungen nennen eine Unit, die es nicht gibt:\n\n  %s\n\n"
            .'`systemctl` meldet für eine unbekannte Unit `inactive` und für ein `stop` darauf '
            .'keinen Fehler, der auffällt — die Anweisung tut dann nichts und sieht aus, als hätte '
            .'sie gewirkt.',
            implode("\n  ", array_unique($fehler)),
        ));
    }

    /**
     * Löst der Name auf eine paketierte oder transiente Unit auf?
     *
     * @param  list<string>  $vorhanden
     */
    private function resolves(string $name, array $vorhanden): bool
    {
        if (in_array($name, $vorhanden, true)) {
            return true;
        }

        // Ohne Endung geschrieben: `systemctl stop srvpanel-worker` meint
        // `srvpanel-worker.service`. systemd ergänzt `.service` und sonst
        // nichts — ein Timer muss ausgeschrieben werden.
        if (in_array($name.'.service', $vorhanden, true)) {
            return true;
        }

        foreach ($this->transientUnits() as $transient) {
            if ($name === $transient || str_starts_with($name, $transient)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Units, die erst zur Laufzeit entstehen.
     *
     * **Sie stehen nicht unter `packaging/systemd` und sind trotzdem echt:**
     * `systemd-run --unit=…` legt sie an, und eine Vorschrift darf sie nennen
     * — `docs/85` fragt `systemctl list-timers srvpanel-reboot` und
     * `list-units "srvpanel-update-*"`, beides zu Recht.
     *
     * **Gelesen aus dem Agenten und nicht als Liste hier.** Eine zweite
     * Fassung der Namen wäre die, die bei der nächsten Umbenennung veraltet —
     * und sie veraltete zur sicheren Seite hin *falsch*: Der Wächter bliebe
     * grün für einen Namen, den es nicht mehr gibt.
     *
     * @return list<string>
     */
    private function transientUnits(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $namen = [];

        foreach (['agent/src/AptLock.php', 'agent/src/Ops/SystemReboot.php'] as $datei) {
            $quelle = (string) file_get_contents($wurzel.'/'.$datei);

            if (preg_match_all("/const\s+UNIT(?:_PREFIX)?\s*=\s*'([^']+)'/", $quelle, $treffer) === 0) {
                continue;
            }

            foreach ($treffer[1] as $wert) {
                $namen[] = $wert;
            }
        }

        return $namen;
    }

    /**
     * Eine Ausnahme ohne Grund ist keine.
     *
     * **Sonst wird die Marke zur Gewohnheit.** `<!-- abschrift: -->` ohne Text
     * schaltet den Wächter für einen Block ab und sagt niemandem, warum; die
     * nächste Abschrift bekäme sie durch Abschreiben, und die übernächste
     * wäre eine Anweisung.
     */
    public function test_every_exemption_carries_a_reason(): void
    {
        $fehler = [];
        $marken = 0;

        foreach ((array) glob(dirname(__DIR__, 2).'/docs/*.md') as $pfad) {
            if (! is_string($pfad)) {
                continue;
            }

            preg_match_all('/<!--\s*abschrift:([^>]*)-->/', (string) file_get_contents($pfad), $treffer);

            foreach ($treffer[1] as $grund) {
                $marken++;

                if (trim(rtrim(trim($grund), '-')) === '') {
                    $fehler[] = basename($pfad);
                }
            }
        }

        $this->assertSame([], $fehler, sprintf(
            "Diese Dokumente nehmen einen Codeblock ohne Begründung aus:\n\n  %s",
            implode("\n  ", $fehler),
        ));

        $this->assertGreaterThan(
            0,
            $marken,
            'Keine einzige Ausnahme gefunden — der Ausdruck greift nicht mehr, '
            .'und dann ist die Prüfung darüber wertlos.',
        );
    }

    /**
     * Die Namen der paketierten Units.
     *
     * @return list<string>
     */
    private function packagedUnits(): array
    {
        $namen = [];

        foreach ((array) glob(dirname(__DIR__, 2).'/'.self::UNITS.'/*') as $pfad) {
            if (is_string($pfad) && is_file($pfad)) {
                $namen[] = basename($pfad);
            }
        }

        return $namen;
    }

    /**
     * Was ein Mensch abtippt: Codeblöcke der Dokumente und die Skripte selbst.
     *
     * @return array<string, string>
     */
    private function instructions(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $ergebnis = [];

        foreach ((array) glob($wurzel.'/docs/*.md') as $pfad) {
            if (! is_string($pfad)) {
                continue;
            }

            $text = (string) file_get_contents($pfad);

            /*
             * Nur, was zwischen ``` steht. Der Fliesstext daneben erklärt
             * gerade, dass ein Name falsch war, und zitiert ihn dabei.
             */
            preg_match_all('/(?:^<!--\s*abschrift:[^>]*-->\s*\n)?^```[^\n]*\n(.*?)^```/ms', $text, $bloecke, PREG_SET_ORDER);

            $teile = [];

            foreach ($bloecke as $block) {
                /*
                 * **Eine Abschrift ist keine Anweisung.** Ein Protokoll hält
                 * fest, was getippt wurde — und der Sinn des Eintrags kann
                 * gerade sein, dass es falsch war. Der Wächter überspringt
                 * einen Block, über dem `<!-- abschrift: … -->` steht.
                 *
                 * **Die Marke trägt ihren Grund und steht daneben**, nicht in
                 * einer Liste in diesem Test: Eine Ausnahme, die man beim
                 * Lesen des Dokuments sieht, veraltet nicht unbemerkt.
                 */
                if (str_starts_with($block[0], '<!--')) {
                    continue;
                }

                $teile[] = $block[1];
            }

            $rumpf = implode("\n", $teile);

            if (trim($rumpf) !== '') {
                $ergebnis['docs/'.basename($pfad)] = $rumpf;
            }
        }

        foreach (['tests/*.sh', 'packaging/bin/*', 'packaging/*.sh'] as $muster) {
            foreach ((array) glob($wurzel.'/'.$muster) as $pfad) {
                if (! is_string($pfad) || ! is_file($pfad)) {
                    continue;
                }

                /*
                 * **Das Bruchskript muss jede verbotene Form enthalten** — sein
                 * Eingriff zu dieser Regel schreibt den falschen Unitnamen in
                 * ein Dokument, und ohne ihn im Text gäbe es den Eingriff nicht.
                 * Es ruft dabei selbst nie `systemctl`; es schreibt Zeichenketten.
                 *
                 * > **Ein Bruchskript, das seine eigene Regel verletzt, ist
                 * > kein Verstoss — es ist der Beleg.**
                 */
                if (basename($pfad) === 'waechter-brechen.sh') {
                    continue;
                }

                $ergebnis[substr($pfad, strlen($wurzel) + 1)] = $this->withoutHashComments(
                    (string) file_get_contents($pfad),
                );
            }
        }

        return $ergebnis;
    }
}
