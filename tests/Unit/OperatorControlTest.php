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
     * Die Wächtervariablen einer Seite: Fähigkeit => Name der Variablen.
     *
     * Gesucht wird ein `const <name> = computed(...)`, in dessen Rumpf der
     * Name der Fähigkeit als Zeichenkette steht.
     *
     * @return array<string, string>
     */
    private function guardsIn(string $sfc): array
    {
        preg_match_all(
            "/const\s+(\w+)\s*=\s*computed\((.*?)\n\)/s",
            $sfc,
            $treffer,
            PREG_SET_ORDER,
        );

        $waechter = [];

        foreach ($treffer as $t) {
            foreach (array_keys(AdminAbility::abilities()) as $ability) {
                if (str_contains($t[2], "'".$ability."'")) {
                    $waechter[$ability] = $t[1];
                }
            }
        }

        return $waechter;
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
     * Steht die Fundstelle innerhalb eines Elements mit diesem `v-if`?
     *
     * **Ein Stapel und keine Rückwärtssuche.** Vorwärts gelesen ist jedes
     * offene Element bekannt, wenn die Fundstelle kommt; rückwärts müsste man
     * raten, welches `<div>` zu welchem `</div>` gehört.
     */
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

    /** @return list<string> */
    private function pages(): array
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo().'/resources/js/Pages'),
        );

        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'vue') {
                $treffer[] = $datei->getPathname();
            }
        }

        sort($treffer);

        return $treffer;
    }

    public function test_a_control_for_a_stricter_route_sits_behind_its_ability(): void
    {
        $routen = $this->abilityOfRoute();
        $offen = [];
        $geprueft = 0;

        foreach ($this->pages() as $pfad) {
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

                // Verlangt die Seite selbst schon diese Fähigkeit, ist jeder
                // Betrachter berechtigt — dann gibt es nichts zu verstecken.
                if (! isset($waechter[$noetig])) {
                    continue;
                }

                $geprueft++;

                foreach ($this->occurrences($template, $name) as $offset) {
                    if (! $this->guarded($template, $offset, $waechter[$noetig])) {
                        $offen[] = sprintf(
                            '%s: %s() ruft %s (%s) und steht nicht in `v-if="%s"`',
                            basename(dirname($pfad)).'/'.basename($pfad),
                            $name,
                            $ziel,
                            $noetig,
                            $waechter[$noetig],
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
