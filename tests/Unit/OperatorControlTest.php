<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Authorization\AdminAbility;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Ein Griff zu einer strengeren Route steht hinter der Fähigkeit, die sie
 * verlangt.
 *
 * ## Der Fall, den es vorher nicht gab
 *
 * Bis zum 27. August 2026 galt je Seite **eine** Fähigkeit: Wer sie nicht
 * hatte, bekam einen 403 und sah gar nichts. `AbilityReachTest` genügte
 * deshalb — er prüft die Ablage `can`, die eine Seite über ihr eigenes Objekt
 * schickt.
 *
 * Die Updates-Seite ist der erste Fall der anderen Art: Sie öffnet sich über
 * `inspect-server`, und einzelne Griffe darauf verlangen `operate-server`
 * (`docs/81 §3` Frage 2). Ein Knopf, der nur einen 403 einbringt, ist keine
 * Auskunft, sondern eine Sackgasse — und beim fünften Bedienelement denkt
 * niemand mehr daran.
 *
 * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
 * > wieder da, wenn die Vermeidung nicht die Regel wurde.**
 *
 * ## Was hier gemessen wird
 *
 * Für jede Seite: welche Fähigkeit ihre eigene Route verlangt, welche Griffe
 * sie auslöst und was deren Routen verlangen. Wo das auseinandergeht, muss
 * das Bedienelement in der Vorlage innerhalb eines `v-if` stehen, das die
 * strengere Fähigkeit abfragt.
 *
 * **Die Zuordnung Fähigkeit → Wächtervariable kommt aus der Seite selbst.**
 * Eine Liste im Test wäre die zweite Fassung, und die zweite veraltet: Sie
 * bliebe grün für eine Seite, die ihre Variable umbenennt.
 *
 * ## Ein Verweis ist ein Bedienelement
 *
 * Bis zum 31. August 2026 sah dieser Test nur `@click`-Griffe, deren Rumpf ein
 * `router.post|put|patch|delete` enthält. Ein `<Link href="/services">` führt
 * genauso zu einem 403 und war für ihn nicht da — die Übersicht hat am
 * 31. August den ersten bekommen.
 *
 * > **Ein Wächter, der die gewohnte Schreibweise kennt, prüft die Gewohnheit
 * > und nicht die Regel.**
 *
 * Gemessen vor der Erweiterung: In `resources/js/Pages` stehen **drei**
 * wörtliche Verweise auf fähigkeitsgeschützte Routen, alle drei auf
 * Kontoseiten, die dieselbe Fähigkeit schon selbst verlangen.
 *
 * ## Was er nicht hält, und warum
 *
 * Eine Seite wird nur geprüft, wenn sie für die nötige Fähigkeit **eine
 * Wächtervariable erklärt**. Fehlt die, wird übersprungen — und das ist für
 * die drei Kontoseiten richtig (ihre eigene Route verlangt die Fähigkeit) und
 * für eine Seite, die den Verweis einfach ungeschützt zeigt, falsch.
 *
 * Zu unterscheiden wären die beiden nur über die Fähigkeit der **eigenen**
 * Route, und die Kette Seitenname → Controller → Pfad → Fähigkeit gibt es in
 * diesem Repo nicht. Das steht hier als Frage und nicht als Zusage.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und
 * > nicht als Zusage.**
 */
final class OperatorControlTest extends TestCase
{
    use WithoutPhpComments;

    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Pfad => Fähigkeit, aus `routes/web.php`.
     *
     * Die Namen kommen aus {@see AdminAbility::abilities()} — derselbe Griff
     * wie in {@see AdminPayloadTest}, und aus demselben Grund: Ein Ausdruck
     * mit eigener Namensliste sieht die nächste Fähigkeit nicht.
     *
     * @return array<string, string>
     */
    private function abilityOfRoute(): array
    {
        $quelle = $this->withoutComments((string) file_get_contents($this->repo().'/routes/web.php'));

        preg_match_all(
            sprintf(
                "/Route::(get|post|put|patch|delete)\('([^']+)'[^;]*?->middleware\(\[?'can:(%s)'/s",
                implode('|', array_map(
                    static fn (string $ability): string => preg_quote($ability, '/'),
                    array_keys(AdminAbility::abilities()),
                )),
            ),
            $quelle,
            $treffer,
            PREG_SET_ORDER,
        );

        $karte = [];

        foreach ($treffer as $t) {
            $karte[$t[2]] = $t[3];
        }

        return $karte;
    }

    /**
     * Die Wächtervariablen einer Vorlage: Fähigkeit => Namen der Variablen.
     *
     * Zwei Quellen, und beide sind nötig:
     *
     * 1. Ein `computed`, in dessen Rumpf der Name der Fähigkeit als
     *    Zeichenkette steht — der gewöhnliche Fall, `abilities['…']`.
     * 2. Ein `computed`, das eine **geteilte Eigenschaft** liest, die die
     *    Mittelschicht nur unter dieser Fähigkeit herausgibt
     *    ({@see self::propGuards()}). Der Wert ist dann `null`, wenn der
     *    Betrachter sie nicht haben darf, und ein `v-if` darauf ist dieselbe
     *    Grenze — nur einen Schritt früher gezogen.
     *
     * **Die zweite Quelle ist keine Nachsicht, sondern die Vermeidung einer
     * zweiten Fassung.** Verlangte dieser Wächter auch dort die Fähigkeit im
     * Rumpf, stünde die Entscheidung zweimal da: einmal in `share()` und
     * einmal in der Vorlage — und die zweite ist die, die veraltet.
     *
     * **Die Klammern werden gezählt und nicht gesucht.** Bis zum
     * 12. September 2026 endete der Ausdruck hier auf `\n)`, und ein
     * einzeiliges `const x = computed(() => …)` hat kein solches Ende: Der
     * Treffer lief bis zur nächsten mehrzeiligen Klammer weiter und schrieb
     * die Fähigkeit einer Variablen zu, die damit nichts zu tun hat. Gemessen
     * an `PanelLayout.vue`, wo `fehler` auf diese Weise zum Wächter für
     * `operate-server` wurde.
     *
     * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
     * > gemessen — er hat an dieser Stelle etwas Falsches gemessen.**
     *
     * @return array<string, list<string>>
     */
    private function guardsIn(string $sfc): array
    {
        $props = $this->propGuards();
        $waechter = [];

        foreach ($this->computedIn($sfc) as $name => $rumpf) {
            foreach (array_keys(AdminAbility::abilities()) as $ability) {
                if (str_contains($rumpf, "'".$ability."'")) {
                    $waechter[$ability][] = $name;
                }
            }

            foreach ($props as $prop => $ability) {
                if (preg_match('/\bprops\.'.preg_quote($prop, '/').'\b/', $rumpf) === 1) {
                    $waechter[$ability][] = $name;
                }
            }
        }

        return array_map(
            static fn (array $namen): array => array_values(array_unique($namen)),
            $waechter,
        );
    }

    /**
     * Jedes `const <name> = computed(…)` einer Vorlage: Name => Rumpf.
     *
     * Die Klammern werden gezählt, statt ein Ende zu erraten. Zeichenketten
     * bleiben dabei unangetastet — eine Klammer in `'…)…'` zählte sonst mit
     * und schnitte den Rumpf an der falschen Stelle ab.
     *
     * @return array<string, string>
     */
    private function computedIn(string $sfc): array
    {
        $gefunden = [];

        if (preg_match_all('/const\s+(\w+)\s*=\s*computed\(/', $sfc, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return $gefunden;
        }

        foreach ($treffer as $t) {
            $von = $t[0][1] + strlen($t[0][0]);
            $tiefe = 1;
            $i = $von;
            $laenge = strlen($sfc);
            $zeichen = null;

            while ($i < $laenge && $tiefe > 0) {
                $c = $sfc[$i];

                if ($zeichen !== null) {
                    if ($c === '\\') {
                        $i += 2;

                        continue;
                    }

                    if ($c === $zeichen) {
                        $zeichen = null;
                    }
                } elseif ($c === "'" || $c === '"' || $c === '`') {
                    $zeichen = $c;
                } elseif ($c === '(') {
                    $tiefe++;
                } elseif ($c === ')') {
                    $tiefe--;
                }

                $i++;
            }

            $gefunden[$t[1][0]] = substr($sfc, $von, $i - $von - 1);
        }

        return $gefunden;
    }

    /**
     * Geteilte Eigenschaften, die die Mittelschicht an eine Fähigkeit bindet:
     * Name der Eigenschaft => Fähigkeit.
     *
     * Gelesen wird `HandleInertiaRequests` und nicht eine Liste in diesem
     * Test: Eine Liste hier wäre die zweite Fassung dessen, was `share()`
     * entscheidet.
     *
     * @return array<string, string>
     */
    private function propGuards(): array
    {
        $quelle = (string) file_get_contents($this->repo().'/app/Http/Middleware/HandleInertiaRequests.php');

        preg_match_all(
            "/'(\w+)'\s*=>\s*fn\s*\([^)]*\)[^=]*=>.*?AdminAbility::(\w+)/s",
            $quelle,
            $treffer,
            PREG_SET_ORDER,
        );

        $karte = [];
        $konstanten = ['OPERATE_SERVER' => 'operate-server', 'INSPECT_SERVER' => 'inspect-server', 'MANAGE_SETTINGS' => 'manage-settings'];

        foreach ($treffer as $t) {
            if (isset($konstanten[$t[2]])) {
                $karte[$t[1]] = $konstanten[$t[2]];
            }
        }

        return $karte;
    }

    /**
     * Die Griffe einer Seite: Name der Funktion => angesteuerter Pfad.
     *
     * @return array<string, string>
     */
    private function handlersIn(string $sfc): array
    {
        preg_match_all(
            "/function\s+(\w+)\s*\([^)]*\)[^{]*\{(.*?)\n\}/s",
            $sfc,
            $treffer,
            PREG_SET_ORDER,
        );

        $griffe = [];

        foreach ($treffer as $t) {
            if (preg_match("/router\.(?:post|put|patch|delete)\(\s*'([^']+)'/", $t[2], $ziel) === 1) {
                $griffe[$t[1]] = $ziel[1];
            }
        }

        return $griffe;
    }

    /**
     * Die wörtlichen Verweise einer Vorlage: Zielpfad => Fundstellen.
     *
     * **Nur wörtliche.** Ein `:href="`/subscriptions/${id}/…`"` lässt sich hier
     * nicht auflösen, und ein Ausdruck, der rät, meldete Löcher, die es nicht
     * gibt — dieses Repo hat sich das mit `RevealTest` schon einmal geleistet.
     * Der abschliessende Schrägstrich fällt weg, damit `/services` und
     * `/services/` dasselbe Ziel sind.
     *
     * @return array<string, list<int>>
     */
    private function linksIn(string $template): array
    {
        preg_match_all('/\bhref="([^"{`$]+)"/', $template, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $verweise = [];

        foreach ($treffer as $t) {
            $ziel = rtrim($t[1][0], '/');

            if ($ziel === '') {
                continue;
            }

            $verweise[$ziel][] = (int) $t[0][1];
        }

        return $verweise;
    }

    /**
     * Steht die Fundstelle innerhalb eines Elements mit diesem `v-if`?
     *
     * **Ein Stapel und keine Rückwärtssuche.** Vorwärts gelesen ist jedes
     * offene Element bekannt, wenn die Fundstelle kommt; rückwärts müsste man
     * raten, welches `<div>` zu welchem `</div>` gehört.
     */
    /**
     * Steht diese Stelle in **einem** der Wächter dieser Fähigkeit?
     *
     * Mehrere sind der Normalfall, seit eine Fähigkeit über zwei Wege sichtbar
     * wird: als Zeichenkette in einem `computed` und als geteilte Eigenschaft,
     * die die Mittelschicht daran bindet. Es genügt einer — sie ziehen
     * dieselbe Grenze.
     *
     * @param  list<string>  $guards
     */
    private function guardedByAny(string $template, int $offset, array $guards): bool
    {
        foreach ($guards as $guard) {
            if ($this->guarded($template, $offset, $guard)) {
                return true;
            }
        }

        return false;
    }

    private function guarded(string $template, int $offset, string $guard): bool
    {
        $leer = ['input', 'img', 'br', 'hr', 'meta', 'link', 'source', 'col'];
        $stapel = [];

        preg_match_all('/<(\/?)([A-Za-z][\w-]*)((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/s',
            $template, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as $tag) {
            $pos = $tag[0][1];

            if ($pos > $offset) {
                break;
            }

            [$roh, $schluss, $name, $attribute, $selbst] = array_column($tag, 0);

            if ($schluss === '/') {
                array_pop($stapel);

                continue;
            }

            if ($selbst === '/' || in_array(strtolower($name), $leer, true)) {
                // Ein Element ohne Inhalt kann nichts umschliessen — aber es
                // kann selbst der Fund sein, und dann zählt sein eigenes v-if.
                if ($pos + strlen($roh) > $offset && str_contains($attribute, 'v-if="'.$guard.'"')) {
                    return true;
                }

                continue;
            }

            $stapel[] = $attribute;
        }

        foreach ($stapel as $attribute) {
            if (str_contains($attribute, 'v-if="'.$guard.'"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein einzeiliges `computed` verschluckt nicht, was hinter ihm steht.
     *
     * **Der Fall, der den Klammerzähler trägt.** Bis zum 12. September 2026
     * endete der Ausdruck hier auf `\n)`, und ein
     * `const x = computed(() => …)` hat kein solches Ende: Der Treffer lief
     * bis zur nächsten mehrzeiligen Klammer weiter. Gemessen an
     * `PanelLayout.vue`, wo `fehler` — ein Leser der Fehlermeldung — auf diese
     * Weise zum Wächter für `operate-server` wurde.
     *
     * > **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
     * > gemessen — er hat an dieser Stelle etwas Falsches gemessen.**
     *
     * Die gefährliche Richtung ist dabei nicht das falsche Rot, das der Fund
     * ausgelöst hat, sondern das falsche Grün daneben: Ein Bedienelement, das
     * in einem `v-if` auf die **unbeteiligte** Variable steht, käme damit
     * durch.
     *
     * Der Prüfkörper ist hier selbst geschrieben und keine Datei des Repos —
     * eine Datei, die zufällig heute so aussieht, sieht morgen anders aus.
     */
    public function test_a_one_line_computed_does_not_swallow_what_follows(): void
    {
        $sfc = <<<'VUE'
            const fehler = computed(() => page.props.flash?.error)

            const liste = computed(() => [
              { name: 'Updates', ability: 'operate-server' },
            ])
            VUE;

        $waechter = $this->guardsIn($sfc);

        $this->assertSame(
            ['liste'],
            $waechter['operate-server'] ?? [],
            implode("\n", [
                'Der Ausdruck über `computed(…)` greift über sein eigenes Ende hinaus.',
                '',
                'Er schreibt die Fähigkeit damit einer Variablen zu, die nichts mit ihr zu',
                'tun hat — und ein Bedienelement in einem `v-if` auf diese Variable käme',
                'durch, obwohl niemand es versteckt.',
            ]),
        );
    }

    /**
     * Jede `.vue` unter `resources/js` — Seiten, Komponenten und die Hülle.
     *
     * **Hier stand einmal ein zweiter Sammler nur über `Pages`**, und die
     * tragende Regel benutzte ihn. `PanelLayout.vue` liegt unter `Layouts` und
     * war damit von ihr nicht erreichbar — obwohl es die Hülle **jeder** Seite
     * ist und seit dem 12. September einen Verweis auf eine Adminroute trägt.
     *
     * > **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über
     * > die Datei, die niemand in diesen Ordner gelegt hat.**
     *
     * @return list<string>
     */
    private function templates(): array
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo().'/resources/js'),
        );

        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $treffer[] = $datei->getPathname();
            }
        }

        sort($treffer);

        return $treffer;
    }

    /**
     * Jeder Schlüssel, den eine Vorlage aus der geteilten Ablage liest, gibt es.
     *
     * **Ein unbekannter Schlüssel ist wortlos `false`.** `abilities` kommt als
     * gewöhnliches Objekt im Payload; `abilities['operate_server']` mit
     * Unterstrich statt Bindestrich ergibt `undefined`, der Vergleich mit
     * `true` ergibt `false`, und die Seite sieht für den Betreiber aus wie für
     * den Administrator — jeder Knopf fort, und keine Meldung nirgends.
     *
     * ## Warum es diese Prüfung erst seit dem 31. August 2026 gibt
     *
     * Den Fall gab es im Bruchskript längst, und er biss — aber nicht an dieser
     * Regel, sondern an der **Untergrenze** von
     * `test_a_control_for_a_stricter_route_sits_behind_its_ability`: Die
     * Updates-Seite war die einzige, die dort etwas zu prüfen beitrug, und mit
     * kaputtem Schlüssel fand der Test ihre Wächtervariable nicht mehr, zählte
     * null und wurde deshalb rot.
     *
     * Der Verweis der Übersicht auf `/services` hat einen zweiten Beitrag
     * dazugestellt. Damit steht die Untergrenze auf 1, wenn die Updates-Seite
     * bricht — und der Eingriff biss nicht mehr.
     *
     * > **Ein Eingriff geht nicht nur kaputt, wenn seine Zielstelle umzieht —
     * > auch, wenn jemand neben seiner Regel eine zweite baut, die dieselbe
     * > Frage beantwortet.**
     *
     * Die eigentliche Lehre ist aber eine über den Eingriff selbst: Er hat nie
     * belegt, dass ein kaputter Schlüssel **als solcher** auffällt, sondern nur,
     * dass danach nichts mehr zu prüfen war.
     *
     * > **Ein Eingriff, der an der Untergrenze eines Wächters beisst, hat über
     * > dessen Regel nichts gesagt.**
     *
     * Diese Prüfung fragt den Schlüssel selbst und kommt ohne Untergrenze von
     * anderswo aus.
     */
    public function test_every_ability_key_a_page_reads_exists(): void
    {
        $bekannt = array_keys(AdminAbility::abilities());
        $gelesen = 0;
        $unbekannt = [];

        foreach ($this->templates() as $pfad) {
            $quelle = (string) file_get_contents($pfad);

            // Nur die wörtlichen Griffe in die geteilte Ablage. `PanelLayout`
            // schlägt über `item.ability` nach, und dass dort eine gültige
            // Fähigkeit steht, hält `AdminPayloadTest` an der Navigation.
            preg_match_all("/\babilities[^;\n]*?\)\['([^']+)'\]/", $quelle, $treffer);

            foreach ($treffer[1] as $schluessel) {
                $gelesen++;

                if (! in_array($schluessel, $bekannt, true)) {
                    $unbekannt[] = sprintf('%s liest `%s`', basename($pfad), $schluessel);
                }
            }
        }

        $this->assertGreaterThan(0, $gelesen,
            'Keine Vorlage liest die geteilte Ablage — dann prüft dieser Test nichts.');

        $this->assertSame([], $unbekannt, sprintf(
            "Diese Schlüssel gibt es in der geteilten Ablage nicht:\n\n  %s\n\n"
            .'Ein unbekannter Schlüssel ist wortlos `false` — die Seite verliert jeden Knopf, ohne es zu sagen.',
            implode("\n  ", $unbekannt),
        ));
    }

    public function test_a_control_for_a_stricter_route_sits_behind_its_ability(): void
    {
        $routen = $this->abilityOfRoute();
        $offen = [];
        $geprueft = 0;

        foreach ($this->templates() as $pfad) {
            $sfc = (string) file_get_contents($pfad);
            $griffe = $this->handlersIn($sfc);
            $waechter = $this->guardsIn($sfc);

            $von = strpos($sfc, '<template>');

            if ($von === false) {
                continue;
            }

            $template = substr($sfc, $von);

            foreach ($griffe as $name => $ziel) {
                $noetig = $routen[$ziel] ?? null;

                if ($noetig === null) {
                    continue;
                }

                // Erklärt die Seite für diese Fähigkeit keine Wächtervariable,
                // unterscheidet sie ihre Betrachter nicht — dann gibt es hier
                // nichts zu verstecken. Was diese Bedingung nicht trennt, steht
                // im Kopf dieser Klasse als offene Frage.
                if (! isset($waechter[$noetig])) {
                    continue;
                }

                $geprueft++;

                foreach ($this->occurrences($template, $name) as $offset) {
                    if (! $this->guardedByAny($template, $offset, $waechter[$noetig])) {
                        $offen[] = sprintf(
                            '%s: %s() ruft %s (%s) und steht in keinem `v-if` auf %s',
                            basename(dirname($pfad)).'/'.basename($pfad),
                            $name,
                            $ziel,
                            $noetig,
                            implode(' oder ', $waechter[$noetig]),
                        );
                    }
                }
            }

            foreach ($this->linksIn($template) as $ziel => $stellen) {
                $noetig = $routen[$ziel] ?? null;

                if ($noetig === null || ! isset($waechter[$noetig])) {
                    continue;
                }

                $geprueft++;

                foreach ($stellen as $offset) {
                    if (! $this->guardedByAny($template, $offset, $waechter[$noetig])) {
                        $offen[] = sprintf(
                            '%s: der Verweis auf %s (%s) steht in keinem `v-if` auf %s',
                            basename(dirname($pfad)).'/'.basename($pfad),
                            $ziel,
                            $noetig,
                            implode(' oder ', $waechter[$noetig]),
                        );
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $geprueft,
            'Keine Seite trägt einen Griff zu einer strengeren Route — dann prüft dieser Test nichts.');

        $this->assertSame([], $offen, sprintf(
            "Diese Bedienelemente werden einem Betrachter gezeigt, der sie nicht drücken darf:\n\n  %s\n\n"
            .'Ein Knopf, der nur einen 403 einbringt, ist keine Auskunft, sondern eine Sackgasse.',
            implode("\n  ", $offen),
        ));
    }

    /** @return list<int> */
    private function occurrences(string $template, string $name): array
    {
        preg_match_all('/@click="'.preg_quote($name, '/').'\b/', $template, $treffer, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $t): int => (int) $t[1], $treffer[0]);
    }
}
