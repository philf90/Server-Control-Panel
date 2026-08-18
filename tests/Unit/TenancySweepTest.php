<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Mandantenlauf klappert jede Route ab, die es gibt — und keine, die es
 * nicht gibt.
 *
 * **Punkt 11 des Abnahmekriteriums zählt Routen** (`docs/51 §4`: „in **jeder**
 * der 22 Routen mit `{subscription}`"), und `tests/mandant-messen.js` trägt die
 * Liste dieser Routen ein zweites Mal. Zwei Listen, die dasselbe meinen, laufen
 * auseinander — und hier fällt es niemandem auf: Eine neue Route, die im
 * Skript fehlt, wird nicht gemessen, und der Lauf meldet trotzdem „alle
 * gehalten".
 *
 * > **Ein Lauf, der zählt, was er kennt, misst sein Gedächtnis.**
 *
 * Deshalb vergleicht dieser Wächter beide Seiten. Er ist der Grund, warum die
 * Zahl im Kriterium („22") stehen bleiben darf: Sie wird nachgerechnet und
 * nicht geglaubt.
 *
 * **Was er nicht prüft:** ob der Lauf die Routen richtig *misst*. Das kann kein
 * Test sagen — es braucht eine echte Sitzung eines echten Kunden gegen einen
 * echten Server, und genau deshalb ist der Lauf ein Konsolen-Schnipsel und kein
 * Testfall.
 */
final class TenancySweepTest extends TestCase
{
    /**
     * Beide Listen nennen dieselben Routen.
     */
    public function test_the_sweep_covers_every_subscription_route(): void
    {
        $ausDenRouten = $this->fromRoutes();
        $ausDemLauf = $this->fromSweep();

        // **Die Untergrenze zählt mit.** Läuft einer der beiden Ausdrücke ins
        // Leere, verglichen sich zwei leere Listen zu „gleich" — und der
        // Wächter wäre grün, ohne etwas gesehen zu haben.
        $this->assertGreaterThanOrEqual(
            20,
            count($ausDenRouten),
            'Der Ausdruck über routes/web.php findet fast nichts — er trifft nicht mehr.',
        );

        $fehlend = array_values(array_diff($ausDenRouten, $ausDemLauf));
        $ueberzaehlig = array_values(array_diff($ausDemLauf, $ausDenRouten));

        $this->assertSame([], $fehlend, implode("\n", [
            'tests/mandant-messen.js misst diese Routen nicht:',
            ...$fehlend,
            'Eine Route, die der Lauf nicht kennt, wird nicht gemessen — und er',
            'meldet trotzdem „alle gehalten".',
        ]));

        $this->assertSame([], $ueberzaehlig, implode("\n", [
            'tests/mandant-messen.js misst Routen, die es nicht mehr gibt:',
            ...$ueberzaehlig,
            'Ein Aufruf ins Leere antwortet mit 404 und sieht aus wie eine',
            'gehaltene Grenze.',
        ]));
    }

    /**
     * Der Lauf prüft seine eigenen Zweitkennungen, bevor er misst.
     *
     * **Am 19. August 2026 lief er mit `eigenJob: 4`** — einer Kennung aus der
     * Messung der Punkte 9 und 10, die auf dem *fremden* Abonnement lag. Drei
     * der 22 Zeilen meldeten daraufhin „BLIEB HÄNGEN", und das liest sich wie
     * ein Befund am Panel. Es war einer an dem, was dem Lauf übergeben wurde.
     *
     * > **Eine Kennung, die man von einer Messung in die nächste mitnimmt,
     * > trägt ihr Abonnement nicht mit.**
     *
     * Gefangen hat es die Gegenprobe — aber erst *nach* dem Lauf und in einer
     * Form, die nach einem Fund am Prüfling aussah. Der Vorflug sagt es vorher
     * und benennt die Kennung.
     *
     * **Geprüft wird nur die eigene Seite**, und das ist kein Versehen: Die
     * fremde liesse sich nur prüfen, indem man die Wand umgeht, die der Lauf
     * messen soll. Sie muss auch nicht — `{subscription}` wird vor `{job}` und
     * `{key}` aufgelöst (die Route trägt kein `scopeBindings()`), der 404 kommt
     * also aus der Mandantenklammer, bevor die Zweitkennung an der Reihe ist.
     */
    public function test_the_sweep_checks_its_own_identifiers_first(): void
    {
        $quelltext = (string) file_get_contents(dirname(__DIR__, 2).'/tests/mandant-messen.js');

        foreach (['eigenJob', 'eigenKey'] as $kennung) {
            $this->assertMatchesRegularExpression(
                '/vorflug\(.+, '.$kennung.', /',
                $quelltext,
                implode("\n", [
                    sprintf('Der Lauf prueft %s nicht, bevor er misst.', $kennung),
                    'Eine Kennung, die nicht auf dem eigenen Abonnement liegt, macht aus',
                    'einem Fehler am Messmittel einen scheinbaren Befund am Panel.',
                ]),
            );
        }

        // **Und der Vorflug darf die fremde Seite nicht anfassen.** Wer sie
        // prueft, umgeht genau die Wand, die dieser Lauf messen soll — und ein
        // Vorflug, der durchkommt, waere selbst der Uebergriff.
        $vorflug = (string) strstr($quelltext, 'const vorflug =');
        $vorflug = (string) strstr($vorflug, 'const zweiter =', true);

        $this->assertNotSame('', $vorflug, 'Den Vorflug gibt es nicht mehr.');

        foreach (['fremdJob', 'fremdKey', '${fremd}'] as $verboten) {
            $this->assertStringNotContainsString(
                $verboten,
                $vorflug,
                sprintf('Der Vorflug fasst „%s" an — damit umgeht er die Wand, die er messen soll.', $verboten),
            );
        }
    }

    /**
     * Die Routen mit `{subscription}` aus `routes/web.php` — Merkmal für
     * Merkmal, damit der Ausdruck nicht versehentlich das halbe Panel einsammelt.
     *
     * @return list<string>
     */
    private function fromRoutes(): array
    {
        $quelltext = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        preg_match_all(
            '#Route::(get|post|put|delete)\(\'/subscriptions/\{subscription\}(/(?:files|sftp|cron)[^\']*)\'#',
            $quelltext,
            $treffer,
            PREG_SET_ORDER,
        );

        $routen = array_map(
            static fn (array $satz): string => strtoupper($satz[1]).' '.$satz[2],
            $treffer,
        );

        sort($routen);

        return $routen;
    }

    /**
     * Und dieselbe Liste, wie der Lauf sie führt.
     *
     * @return list<string>
     */
    private function fromSweep(): array
    {
        $quelltext = (string) file_get_contents(dirname(__DIR__, 2).'/tests/mandant-messen.js');

        $anfang = strpos($quelltext, 'const routen = [');
        $ende = strpos($quelltext, "\n  ]", (int) $anfang);

        $this->assertIsInt($anfang, 'In mandant-messen.js gibt es die Routenliste nicht mehr.');
        $this->assertIsInt($ende, 'Die Routenliste in mandant-messen.js ist nicht abgeschlossen.');

        preg_match_all(
            "#\['(GET|POST|PUT|DELETE)', '([^']+)'#",
            substr($quelltext, (int) $anfang, (int) $ende - (int) $anfang),
            $treffer,
            PREG_SET_ORDER,
        );

        $routen = array_map(
            static fn (array $satz): string => $satz[1].' '.$satz[2],
            $treffer,
        );

        sort($routen);

        return $routen;
    }
}
