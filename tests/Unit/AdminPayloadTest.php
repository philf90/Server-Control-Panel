<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Was eine Rolle nicht sehen darf, wird ihr nicht angeboten — und steht nicht
 * in der Antwort.
 *
 * ## Der gemessene Zustand, und warum daraus ein Wächter wird
 *
 * Am 25. August 2026 sind alle 43 Inertia-Seiten ausgezählt worden: acht gehören
 * dem Betreiber, und in den übrigen 35 steht **kein** Betreibergeheimnis. Die
 * vier Verdachtsfälle waren alle harmlos — `Databases/Show` zeigt dem Kunden
 * sein eigenes, einmalig geflashtes Passwort; `Domains/Show` schickt zu ACME
 * zwei Wahrheitswerte und zu DNS ein Prüfergebnis mit öffentlichen Adressen.
 *
 * **Kriterium 3 aus `docs/82 §6` ist damit erfüllt — und nichts hält es.** Genau
 * dafür steht dieser Wächter da: Er baut nichts um, er hält einen Zustand fest,
 * den eine Messung einmal ergeben hat.
 *
 * > **Ein gemessener Zustand ohne Wächter ist ein Datum von gestern.**
 *
 * ## Drei Zusagen, und die dritte ist die überraschende
 *
 * 1. Kein Seitenwert überschreibt die geteilte Ablage `abilities`.
 * 2. Jeder Menüpunkt, der auf eine Route mit Adminfähigkeit zeigt, trägt sie.
 * 3. Keine Agent-Operation, die ein Geheimnis entgegennimmt, protokolliert eine
 *    Zeile.
 *
 * Die dritte trägt eine Seite, die auf den ersten Blick nichts damit zu tun hat:
 * `/operations/{id}` zeigt `payload`, `result` und `output` **jedem Admin**.
 * `output` sind die Protokollzeilen des Agenten — dieselbe Art Inhalt, deretwegen
 * `/logs` dem Betreiber allein gehört. Dass dort heute nichts Geheimes steht,
 * liegt nicht an einem Filter, sondern daran, dass die zwölf
 * geheimnisführenden Operationen schweigen.
 *
 * > **Eine Seite ist nur so verschwiegen wie das, was sie durchreicht.**
 */
final class AdminPayloadTest extends TestCase
{
    use WithoutPhpComments;

    /** Die geteilte Ablage der Adminfähigkeiten. */
    private const SHARED = 'abilities';

    /**
     * Agent-Operationen, die ein Geheimnis entgegennehmen.
     *
     * Sie dürfen keine Protokollzeile senden, weil die in `Operation.output`
     * landet und `/operations/{id}` sie jedem Admin zeigt.
     *
     * **Die Liste wird nicht gepflegt, sondern gefunden** — der Test unten
     * durchsucht `agent/src/Ops/` selbst. Eine Aufzählung hier wäre die Menge
     * der Operationen, an die jemand gerade gedacht hat.
     *
     * @var list<string>
     */
    private const SECRET_WORDS = ['password', 'secret', 'credential', 'api_key'];

    /**
     * Keine Seite überschreibt die geteilte Ablage.
     *
     * **Der Fehler, gegen den das steht, ist still und trifft nur manche
     * Seiten.** Inertia lässt Seitenwerte über geteilte gewinnen. Hiesse die
     * Ablage `can`, wäre sie auf den neun Seiten fort, die eine eigene
     * `can`-Ablage über *ihr* Objekt schicken — und das Menü verlöre dort seine
     * Einträge, während es überall sonst steht.
     *
     * > **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf dieser
     * > Seite kein geteilter Schlüssel mehr — und der Ausfall sieht aus wie ein
     * > Rechteproblem.**
     */
    public function test_no_page_shadows_the_shared_abilities(): void
    {
        $shadowing = [];
        $renders = 0;

        foreach ($this->controllers() as $relative => $source) {
            $renders += preg_match_all("/Inertia::render\(/", $source);

            /*
             * **Die ganze Datei und nicht der Abschnitt hinter `render(`.**
             *
             * Der erste Wurf las den Text zwischen zwei `Inertia::render(`
             * und suchte darin nach der Einrückung einer Ablage. Er hätte
             * `LogsController` nie gesehen: Der rendert
             * `Inertia::render('Logs/Index', $this->read(…))`, und die Ablage
             * entsteht in einer Hilfsmethode weiter unten. Gefunden hat das
             * nicht das Nachdenken, sondern ein Eingriff, der genau dort
             * ansetzen wollte und keine Zielstelle fand.
             *
             * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat
             * > nicht wenig gemessen — er hat an dieser Stelle gar nicht
             * > gemessen.**
             *
             * Die gröbere Frage ist hier die richtige: In einem Controller hat
             * ein Schlüssel dieses Namens nichts zu suchen, gleich in welcher
             * Methode er steht.
             */
            if (preg_match("/'".self::SHARED."'\s*=>/", $source) === 1) {
                $shadowing[] = $relative;
            }
        }

        $this->assertGreaterThan(30, $renders,
            'Es werden kaum Inertia-Seiten gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $shadowing, sprintf(
            "Diese Seiten schicken einen eigenen Wert namens `%s`:\n\n  %s\n\n"
            .'Seitenwerte überschreiben geteilte. Die Navigation verlöre auf genau diesen Seiten '
            .'ihre Einträge — und nur dort, was sich wie ein Rechteproblem liest.',
            self::SHARED, implode("\n  ", $shadowing),
        ));
    }

    /**
     * Jeder Menüpunkt auf eine Route mit Adminfähigkeit trägt diese Fähigkeit.
     *
     * **Gefragt wird die Route und nicht eine Liste hier.** Wer eine
     * Betreiberseite baut und ihren Menüpunkt ungeschützt lässt, bekommt Rot —
     * ohne dass jemand diesen Test dafür anfassen müsste.
     *
     * > **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
     * > abweist.**
     */
    public function test_every_menu_entry_carries_the_ability_of_its_route(): void
    {
        $routen = $this->guardedRoutes();
        $eintraege = $this->menuEntries();

        $this->assertGreaterThanOrEqual(8, count($routen),
            'Es werden kaum Routen mit Adminfähigkeit gefunden — dann prüft dieser Test nichts.');

        $offen = [];

        foreach ($eintraege as $href => $ability) {
            if (! isset($routen[$href])) {
                continue;
            }

            if ($ability !== $routen[$href]) {
                $offen[] = sprintf('%s: Menü „%s", Route „%s"', $href, $ability ?? 'ohne', $routen[$href]);
            }
        }

        $this->assertSame([], $offen, sprintf(
            "Hier sagt der Menüpunkt etwas anderes als seine Route:\n\n  %s\n\n"
            .'Ein Eintrag ohne Fähigkeit wird jedem gezeigt und gibt dem Falschen einen 403; '
            .'einer mit der falschen versteckt ihn vor dem Richtigen.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Und die Gegenrichtung: keine Fähigkeit am Menü, die die Route nicht hat.
     *
     * Ohne sie bliebe der Test oben auch grün, wenn jemand jedem Eintrag
     * vorsichtshalber `operate-server` gäbe — dann verschwänden „Kunden" und
     * „Abonnements" aus der Navigation des Administrators, also genau die
     * Arbeit, für die es ihn gibt.
     */
    public function test_no_menu_entry_invents_an_ability(): void
    {
        $routen = $this->guardedRoutes();
        $erfunden = [];
        $gepruefte = 0;

        foreach ($this->menuEntries() as $href => $ability) {
            if ($ability === null) {
                continue;
            }

            $gepruefte++;

            if (($routen[$href] ?? null) !== $ability) {
                $erfunden[] = $href.' → '.$ability;
            }
        }

        $this->assertGreaterThanOrEqual(8, $gepruefte,
            'Es trägt kaum ein Menüpunkt eine Fähigkeit — dann prüft dieser Test nichts.');

        $this->assertSame([], $erfunden, sprintf(
            "Diese Menüpunkte tragen eine Fähigkeit, die ihre Route nicht verlangt:\n\n  %s\n\n"
            .'Ein Eintrag, der strenger ist als seine Route, versteckt eine Seite vor jemandem, '
            .'der sie aufrufen darf.',
            implode("\n  ", $erfunden),
        ));
    }

    /**
     * Keine geheimnisführende Agent-Operation protokolliert eine Zeile.
     *
     * `Operation.output` sammelt die `log`-Rahmen des Agenten, und
     * `/operations/{id}` zeigt sie **jedem Admin** — dieselbe Art Inhalt, wegen
     * derer `/logs` dem Betreiber allein gehört. Eine einzige Zeile
     * „Rolle {$user} mit Passwort {$pw} angelegt" wäre der Weg dorthin.
     *
     * **Grob und mit Absicht.** Der Test verbietet jedes `log()` in einer
     * Operation, die ein Geheimnis anfasst — nicht nur eines, das das Geheimnis
     * nennt. Ob eine Zeichenkette am Ende die Variable enthält, kann er nicht
     * sehen; dass gar keine entsteht, schon.
     *
     * > **Eine Zusage, die sich nicht am Inhalt prüfen lässt, prüft man an
     * > seiner Abwesenheit.**
     */
    public function test_no_secret_bearing_operation_logs_a_line(): void
    {
        $sprechende = [];
        $betrachtet = 0;

        foreach (glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php') ?: [] as $datei) {
            $quelle = $this->withoutPhpComments((string) file_get_contents($datei));

            $traegtGeheimnis = false;

            foreach (self::SECRET_WORDS as $wort) {
                if (str_contains($quelle, $wort)) {
                    $traegtGeheimnis = true;

                    break;
                }
            }

            if (! $traegtGeheimnis) {
                continue;
            }

            $betrachtet++;

            if (preg_match('/->log\(|\blog\(/', $quelle) === 1) {
                $sprechende[] = basename($datei);
            }
        }

        $this->assertGreaterThanOrEqual(10, $betrachtet,
            'Es werden kaum geheimnisführende Operationen gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $sprechende, sprintf(
            "Diese Operationen fassen ein Geheimnis an und protokollieren eine Zeile:\n\n  %s\n\n"
            .'Protokollzeilen des Agenten landen in `Operation.output`, und die Vorgangsseite zeigt '
            .'sie jedem Admin — auch dem, der /logs nicht sehen darf.',
            implode("\n  ", $sprechende),
        ));
    }

    /**
     * Routen mit einer Adminfähigkeit: Pfad => Fähigkeit.
     *
     * @return array<string, string>
     */
    private function guardedRoutes(): array
    {
        $quelle = $this->withoutPhpComments($this->read('routes/web.php'));
        $routen = [];

        preg_match_all(
            "/Route::get\('([^']+)'[^;]*?->middleware\(\[?'can:(operate-server|manage-settings)'/s",
            $quelle, $treffer, PREG_SET_ORDER,
        );

        foreach ($treffer as $m) {
            $routen[$m[1]] = $m[2];
        }

        return $routen;
    }

    /**
     * Die Menüpunkte: Adresse => Fähigkeit oder `null`.
     *
     * @return array<string, string|null>
     */
    private function menuEntries(): array
    {
        $quelle = $this->read('resources/js/Layouts/PanelLayout.vue');
        $eintraege = [];

        preg_match_all(
            "/\{ name: '[^']+', href: '([^']+)', icon: '[^']+'(?:, ability: '([^']+)')? \}/",
            $quelle, $treffer, PREG_SET_ORDER,
        );

        foreach ($treffer as $m) {
            $eintraege[$m[1]] = ($m[2] ?? '') !== '' ? $m[2] : null;
        }

        return $eintraege;
    }

    /** @return array<string, string> Pfad => Quelltext */
    private function controllers(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        foreach (glob($wurzel.'/app/Http/Controllers/*.php') ?: [] as $datei) {
            $dateien[substr($datei, strlen($wurzel) + 1)] = $this->withoutPhpComments(
                (string) file_get_contents($datei),
            );
        }

        return $dateien;
    }

    private function withoutPhpComments(string $source): string
    {
        return $this->withoutComments($source);
    }

    private function read(string $relative): string
    {
        $pfad = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($pfad, $relative.' gibt es nicht mehr.');

        return (string) file_get_contents($pfad);
    }
}
