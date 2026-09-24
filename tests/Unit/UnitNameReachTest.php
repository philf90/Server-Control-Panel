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
 * ## Zwei Regeln, überall dieselben
 *
 * **Hinter `systemctl`** muss es jeden eigenen Namen geben, auch einen ohne
 * Endung: `systemctl stop srvpanel-worker` meint `srvpanel-worker.service`.
 * **Mit der Endung einer Unit-Art** gilt dasselbe auch ohne `systemctl`
 * davor. Wer `srvpanel-web.service` liest, tippt es ab, in `journalctl -u`
 * genauso wie in `systemctl`.
 *
 * Beide gelten in Codeblöcken, in Skripten und im Fließtext, mit denselben
 * Ausdrücken. **Nicht** geprüft wird ein Name ohne Endung ausserhalb eines
 * Aufrufs: Dort ist `srvpanel` meist das Kommando und `srvpanel-p1136` eine
 * Datei unter `/etc/cron.d`. Gemessen am 24. September 2026 zeigten im
 * Fließtext 815 solche Stellen auf keine Unit, und 715 davon waren `srvpanel`.
 *
 * ## Der Fließtext
 *
 * **Bis zum 24. September 2026 las dieser Wächter nur Codeblöcke**, weil derselbe
 * Text daneben **erklärt**, dass es den Namen nicht gibt. Wer roh liest, meldet
 * den Absatz, der die Behebung beschreibt — derselbe Fall wie beim Schritt
 * „Oberfläche" der CI, der `resources/js` ohne Rücksicht auf Kommentare liest.
 *
 * > **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
 * > Messung fälschlich rot.**
 *
 * Der Grund stimmte, und er nahm trotzdem zu viel aus. Gemessen an zwei
 * Stellen:
 *
 * - `docs/33` wies im Fließtext `systemctl restart srvpanel-fpm.service` an.
 *   Unter `packaging/systemd` hat es diese Unit nie gegeben; der Pool der
 *   Oberfläche heisst `srvpanel-web.service`.
 * - Ein **eingerückter** Codeblock ist für einen Leser, der nur ``` kennt,
 *   Fließtext. In `docs/87 §1` stand dort bis zum 28. August
 *   `systemctl is-active srvpanel`. Zurückgesetzt auf diese Zeile blieb die
 *   alte Fassung dieses Wächters grün.
 *
 * Erklärt ein Absatz einen falschen Namen, trägt er deshalb eine Marke wie eine
 * Abschrift: `<!-- abschrift: Grund -->` unmittelbar über dem Block. Sie nimmt
 * genau diesen Block aus, bis zur nächsten Leerzeile.
 *
 * > **Eine Marke, die nichts mehr ausnimmt, ist ein Fund.** Wer den Satz
 * > berichtigt und die Marke stehen lässt, hat den Block für den nächsten
 * > falschen Namen freigegeben.
 */
final class UnitNameReachTest extends TestCase
{
    use WithoutHashComments;

    /** Wo die Units paketiert sind. */
    private const UNITS = 'packaging/systemd';

    /**
     * Ein Aufruf von `systemctl` und der Rest seiner Zeile.
     *
     * `systemctl` mit beliebig vielen Schaltern davor, dann ein Verb, dann ein
     * oder mehrere Namen. Gesucht werden die Namen und nicht das Verb: `stop`,
     * `is-active`, `start`, `enable` — die Liste wäre die nächste, die jemand
     * erweitert und vergisst.
     */
    private const CALL = '/\bsystemctl\b([^\n|;&]*)/';

    /** Ein eigener Name — in Codeblock, Skript und Fließtext derselbe Ausdruck. */
    private const NAME = '/(?<![\w.-])(srvpanel[\w.-]*)/';

    /**
     * Die Endung einer Unit-Art.
     *
     * Die Liste gehört systemd und nicht uns: `systemctl --type=help` nennt
     * unter systemd 255 genau diese elf.
     */
    private const UNIT_TYPE = '/\.(?:service|socket|device|mount|automount|swap|target|path|timer|slice|scope)$/';

    /** Eine Ausnahme im Dokument, mit ihrem Grund. */
    private const EXEMPTION = '/<!--\s*abschrift:([^>]*)-->/';

    /**
     * Jeder genannte Unitname ist paketiert — in Codeblöcken und Skripten.
     */
    public function test_every_named_unit_exists(): void
    {
        $vorhanden = $this->packagedUnits();

        $this->assertNotEmpty(
            $vorhanden,
            'Unter '.self::UNITS.' liegen keine Units — der Wächter misst nichts.',
        );

        $fehler = [];
        $gesehen = ['aufruf' => 0, 'endung' => 0];

        foreach ($this->instructions() as $ort => $text) {
            foreach ($this->named($text, false) as $regel => $namen) {
                foreach ($namen as $name) {
                    $gesehen[$regel]++;

                    if (! $this->resolves($name, $vorhanden)) {
                        $fehler[] = sprintf('%s: „%s"', $ort, $name);
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $gesehen['aufruf'],
            'Kein einziger `systemctl`-Aufruf mit einer eigenen Unit gefunden — der Ausdruck greift nicht mehr.',
        );

        $this->assertGreaterThan(
            0,
            $gesehen['endung'],
            'Kein einziger ausgeschriebener Unitname gefunden — der Ausdruck über die Endungen greift nicht mehr.',
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
     * Auch der Fließtext nennt nur Units, die es gibt.
     *
     * Dieselben zwei Regeln wie im Codeblock. Ausgenommen ist ein Block unter
     * einer Marke — und sonst nichts.
     */
    public function test_every_unit_named_in_prose_exists(): void
    {
        $vorhanden = $this->packagedUnits();
        $fehler = [];
        $gesehen = ['aufruf' => 0, 'endung' => 0];

        foreach ($this->documents() as $ort => $text) {
            foreach ($this->pieces($text) as $stueck) {
                if ($stueck['art'] !== 'text' || $stueck['marke'] !== null) {
                    continue;
                }

                foreach ($this->named($stueck['text'], true) as $regel => $namen) {
                    foreach ($namen as $name) {
                        $gesehen[$regel]++;

                        if (! $this->resolves($name, $vorhanden)) {
                            $fehler[] = sprintf('%s:%d: „%s"', $ort, $stueck['zeile'], $name);
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $gesehen['aufruf'],
            'Im Fließtext kein einziger `systemctl`-Aufruf mit einer eigenen Unit — der Leser greift nicht mehr.',
        );

        $this->assertGreaterThan(
            0,
            $gesehen['endung'],
            'Im Fließtext kein einziger ausgeschriebener Unitname — der Leser greift nicht mehr.',
        );

        $this->assertSame([], array_unique($fehler), sprintf(
            "Dieser Fließtext nennt eine Unit, die es nicht gibt:\n\n  %s\n\n"
            .'Wer ihn liest, tippt den Namen ab. Erklärt der Block gerade, dass es den Namen '
            .'nicht gibt, gehört `<!-- abschrift: Grund -->` unmittelbar darüber.',
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
     * Die eigenen Namen eines Stücks, je Regel.
     *
     * **Ein Punkt am Ende gehört im Fließtext zum Satz** und nicht zum Namen:
     * „… läuft als srvpanel-web.service." In einem Codeblock bleibt er stehen,
     * denn dort tippt ihn jemand mit ab.
     *
     * @return array{aufruf: list<string>, endung: list<string>}
     */
    private function named(string $text, bool $fliesstext): array
    {
        $gefunden = ['aufruf' => [], 'endung' => []];
        $bereinigt = static fn (string $roh): string => $fliesstext ? rtrim($roh, '.') : $roh;

        preg_match_all(self::CALL, $text, $aufrufe);

        foreach ($aufrufe[1] as $rest) {
            preg_match_all(self::NAME, $rest, $namen);

            foreach ($namen[1] as $name) {
                $gefunden['aufruf'][] = $bereinigt($name);
            }
        }

        preg_match_all(self::NAME, $text, $namen);

        foreach ($namen[1] as $name) {
            if (preg_match(self::UNIT_TYPE, $bereinigt($name)) === 1) {
                $gefunden['endung'][] = $bereinigt($name);
            }
        }

        return $gefunden;
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

        foreach ($this->documents() as $ort => $text) {
            preg_match_all(self::EXEMPTION, $text, $treffer);

            foreach ($treffer[1] as $grund) {
                $marken++;

                if (trim(rtrim(trim($grund), '-')) === '') {
                    $fehler[] = $ort;
                }
            }
        }

        $this->assertSame([], $fehler, sprintf(
            "Diese Dokumente nehmen einen Block ohne Begründung aus:\n\n  %s",
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
     * Jede Marke nimmt etwas aus, das sonst ein Fund wäre.
     *
     * **Sonst ist sie eine Ausnahme auf Vorrat.** Wer den Satz berichtigt und
     * die Marke stehen lässt, hat den Block für den nächsten falschen Namen
     * freigegeben — und niemand sieht es, weil der Wächter grün ist.
     *
     * Gezählt wird über **jede** Marke im Dokument und nicht nur über die, die
     * über einem Block stehen. Eine Marke mitten in einer Zeile oder über einer
     * Leerzeile nimmt nichts aus und sieht trotzdem aus wie eine Ausnahme.
     */
    public function test_every_exemption_still_covers_a_finding(): void
    {
        $vorhanden = $this->packagedUnits();
        $veraltet = [];
        $marken = 0;

        foreach ($this->documents() as $ort => $text) {
            $tragend = [];

            foreach ($this->pieces($text) as $stueck) {
                if ($stueck['marke'] === null) {
                    continue;
                }

                foreach ($this->named($stueck['text'], $stueck['art'] === 'text') as $namen) {
                    foreach ($namen as $name) {
                        if (! $this->resolves($name, $vorhanden)) {
                            $tragend[$stueck['marke']] = true;
                        }
                    }
                }
            }

            preg_match_all(self::EXEMPTION, $text, $treffer, PREG_OFFSET_CAPTURE);

            foreach ($treffer[0] as [, $stelle]) {
                $marken++;
                $zeile = substr_count($text, "\n", 0, $stelle) + 1;

                if (! isset($tragend[$zeile])) {
                    $veraltet[] = sprintf('%s:%d', $ort, $zeile);
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $marken,
            'Keine einzige Marke gefunden — der Ausdruck greift nicht mehr, '
            .'und dann ist die Prüfung darüber wertlos.',
        );

        $this->assertSame([], $veraltet, sprintf(
            "Diese Marken nehmen nichts aus, was sonst ein Fund wäre:\n\n  %s\n\n"
            .'Steht der Name inzwischen richtig da, gehört die Marke weg — sonst deckt sie den '
            .'nächsten falschen Namen im selben Block.',
            implode("\n  ", $veraltet),
        ));
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
     * Die Dokumente, die dieser Wächter liest.
     *
     * @return array<string, string>
     */
    private function documents(): array
    {
        $dokumente = [];

        foreach ((array) glob(dirname(__DIR__, 2).'/docs/*.md') as $pfad) {
            if (is_string($pfad)) {
                $dokumente['docs/'.basename($pfad)] = (string) file_get_contents($pfad);
            }
        }

        return $dokumente;
    }

    /**
     * Ein Dokument in Stücken: jeder Codeblock eines, jede Zeile Fließtext eine.
     *
     * Zu jedem Stück gehört die Zeile der Marke, die es ausnimmt, sonst `null`.
     *
     * **Eine Marke nimmt genau den Block aus, über dem sie steht.** Das ist ein
     * Codeblock, wenn sein Zaun die nächste Zeile ist, sonst ein Absatz, eine
     * Überschrift oder ein eingerückter Block bis zur nächsten Leerzeile. Ein
     * Codeblock, der auf den Absatz folgt, gehört nicht mehr dazu: Er ist ein
     * neuer Block und oft genau die Anweisung, die gelesen werden soll.
     *
     * Ein Zaun, der nicht schliesst, läuft wie in CommonMark bis zum Ende des
     * Dokuments.
     *
     * @return list<array{art: 'code'|'text', zeile: int, text: string, marke: int|null}>
     */
    private function pieces(string $dokument): array
    {
        $stuecke = [];
        $code = null;

        // Die Marke des laufenden Blocks — und dieselbe nur, solange die Zeile
        // davor die Marke selbst war.
        $marke = null;
        $direkt = null;

        foreach (explode("\n", $dokument) as $i => $zeile) {
            if ($code !== null) {
                if (str_starts_with($zeile, '```')) {
                    $stuecke[] = $code;
                    $code = null;
                } else {
                    $code['text'] .= $zeile."\n";
                }

                continue;
            }

            if (str_starts_with($zeile, '```')) {
                $code = ['art' => 'code', 'zeile' => $i + 1, 'text' => '', 'marke' => $direkt];
                $marke = null;
                $direkt = null;

                continue;
            }

            if (trim($zeile) === '') {
                $marke = null;
                $direkt = null;

                continue;
            }

            if (preg_match(self::EXEMPTION, $zeile, $treffer) === 1 && trim($zeile) === $treffer[0]) {
                $marke = $i + 1;
                $direkt = $i + 1;

                continue;
            }

            $stuecke[] = ['art' => 'text', 'zeile' => $i + 1, 'text' => $zeile, 'marke' => $marke];
            $direkt = null;
        }

        if ($code !== null) {
            $stuecke[] = $code;
        }

        return $stuecke;
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

        foreach ($this->documents() as $ort => $text) {
            $teile = [];

            foreach ($this->pieces($text) as $stueck) {
                /*
                 * Nur, was zwischen ``` steht. Den Fließtext daneben liest
                 * `test_every_unit_named_in_prose_exists()`, mit denselben
                 * Regeln und derselben Marke.
                 *
                 * **Eine Abschrift ist keine Anweisung.** Ein Protokoll hält
                 * fest, was getippt wurde — und der Sinn des Eintrags kann
                 * gerade sein, dass es falsch war. Der Wächter überspringt
                 * einen Block, über dem `<!-- abschrift: … -->` steht.
                 *
                 * **Die Marke trägt ihren Grund und steht daneben**, nicht in
                 * einer Liste in diesem Test: Eine Ausnahme, die man beim
                 * Lesen des Dokuments sieht, veraltet nicht unbemerkt.
                 */
                if ($stueck['art'] === 'code' && $stueck['marke'] === null) {
                    $teile[] = $stueck['text'];
                }
            }

            $rumpf = implode("\n", $teile);

            if (trim($rumpf) !== '') {
                $ergebnis[$ort] = $rumpf;
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
