<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Eine Seite, auf die nichts zeigt, ist nicht ausgeliefert — sie ist nur
 * vorhanden.
 *
 * ## Der Anlass
 *
 * Der Dateimanager war zur Zwischenabnahme von P6 vollständig gebaut: zwölf
 * Routen, jede mit ihrem `can:`, drei Seiten unter `resources/js/Pages/Files/`,
 * eine Policy mit zwei Methoden, zwölf Operationen im Agenten. **Und kein
 * einziges Template nannte `/files`** — weder die Navigation für Kunden noch
 * die für den Betreiber noch `Subscriptions/Show.vue`. Erreichbar war er
 * ausschliesslich über die Adresszeile (`docs/53`, Befund 6).
 *
 * Gefunden hat das keine Prüfung, sondern eine Frage des Betreibers: „Wo finde
 * ich den Dateimanager?"
 *
 * ## Warum kein vorhandener Wächter das sehen konnte
 *
 * Alle prüfen die andere Richtung, und drei davon waren zufrieden:
 *
 * - `RouteAuthorizationTest` — jede Route trägt `can:`. Trug sie.
 * - `PolicyReachTest` — jede Policy-Methode wird benutzt. Wurde sie.
 * - `InertiaPagesTest` — jede gerenderte Seite existiert als Datei. Existierte.
 *
 * `AbilityReachTest` kommt am nächsten dran und sagt genau das Falsche: Er
 * sorgt dafür, dass **nichts Unerlaubtes** angeboten wird.
 *
 * > **Ein Wächter, der prüft, dass nichts Verbotenes gezeigt wird, hat über das
 * > Fehlende nichts gesagt.**
 *
 * ## Was hier geprüft wird
 *
 * Jede **GET**-Route, die eine Inertia-Seite rendert, ist von den Bausteinen
 * aus erreichbar, die auf jeder Seite stehen — der Navigation und den
 * Komponenten. GET, weil nur das eine Seite ist, die man aufruft; ein `POST`
 * ist ein Griff und steht ohnehin in dem Formular, das ihn abschickt.
 *
 * ## Und der erste Wurf hat den eigenen Bruch überlebt
 *
 * Er fragte: „Nennt **irgendein** Template diese Adresse?" Das war grün, als der
 * Weg zum Dateimanager zur Gegenprobe wieder entfernt wurde — denn
 * `Files/Index.vue` enthält `router.get(…/files)` **selbst**, für den Sprung in
 * ein Unterverzeichnis. Die Seite verlinkte sich, und der Wächter war zufrieden.
 *
 * Dasselbe gilt für die drei Dateiseiten untereinander: Liste zeigt auf Editor,
 * Editor zurück auf Liste, Suche auf Editor. Alle drei genannt, keine erreichbar.
 *
 * > **Drei Seiten, die aufeinander zeigen, sind eine Insel ohne Anleger.**
 *
 * Gefragt wird deshalb nach **Erreichbarkeit** und nicht nach Erwähnung: Die
 * Wurzeln sind die Dateien, die keine Seite sind — Layouts und Komponenten,
 * denn die stehen auf jeder Seite. Von dort aus wird gelaufen, und was dabei
 * nicht besucht wird, ist nicht erreichbar.
 *
 * ## Und es gibt keine Ausnahmeliste
 *
 * Der erste Wurf hatte eine, vorsorglich und leer. **PHPStan hat sie gemeldet**,
 * und der Befund ist richtiger als der Typ, um den es dabei ging: Eine leere
 * Ausnahmeliste macht ihre eigene Prüfung zu einer Behauptung ohne Gegenstand —
 * `foreach` über nichts ist immer grün.
 *
 * > **Eine Vorkehrung für einen Fall, den es nicht gibt, sieht aus wie eine
 * > Entscheidung und ist eine Vermutung.**
 *
 * Alle zweiunddreissig Seiten dieses Panels sind erreichbar. Gibt es später eine,
 * die es aus einem echten Grund nicht sein kann, bekommt sie ihre Liste — und
 * dann steht ein Eintrag darin, an dem sich prüfen lässt, dass die Liste wirkt.
 */
final class LinkReachTest extends TestCase
{
    /**
     * Alle Routen aus `routes/web.php`.
     *
     * Gelesen wird die Datei als Text und nicht der Router: Ohne Framework
     * läuft dieser Wächter auch dort, wo `vendor/` fehlt — und das ist der
     * halbe Grund, warum es ihn gibt.
     *
     * @return list<array{verb: string, uri: string, controller: string, method: string, name: string, guarded: bool}>
     */
    private function routes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        preg_match_all(
            '/Route::(get|post|put|patch|delete)\(\s*\'([^\']+)\'\s*,\s*\[\s*([\w\\\\]+)::class\s*,\s*\'(\w+)\'\s*\]\s*\)((?:(?!;).)*);/s',
            $source,
            $treffer,
            PREG_SET_ORDER,
        );

        $routen = [];

        foreach ($treffer as $t) {
            $schwanz = $t[5];

            if (preg_match('/->name\(\s*\'([^\']+)\'\s*\)/', $schwanz, $name) !== 1) {
                continue;
            }

            $routen[] = [
                'verb' => $t[1],
                'uri' => $t[2],
                'controller' => $t[3],
                'method' => $t[4],
                'name' => $name[1],
                'guarded' => str_contains($schwanz, "'can:"),
            ];
        }

        return $routen;
    }

    /**
     * Quelltext ohne Kommentare.
     *
     * ## Der zweite Fall derselben Art, und der erste stand schon im Repo
     *
     * Dieser Wächter las die **ganze** Datei — mit Absicht, denn eine Adresse
     * steht genauso oft in einem `router.get(…)` wie in einem `:href`. Nur:
     * Sie steht auch in einem **Kommentar**, der erklärt, warum sie so heisst.
     *
     * Am 15. August 2026 hat der Fix für Befund 8 (`docs/55`) dem Menü einen
     * Punkt „Dateien" gegeben, und die Begründung dazu nennt beide Adressen
     * wörtlich: „Die Adresse ist `/files` und nicht `/subscriptions/…/files`".
     * Der Bruchlauf entfernte danach **beide** Wege zum Dateimanager — und der
     * Wächter blieb grün, weil er den Erklärtext für einen Link hielt.
     *
     * > **Ein Wächter, der Quelltext nach Adressen durchsucht, findet sie auch
     * > dort, wo jemand über sie schreibt.**
     *
     * `PanelRequestTest` hat genau das schon einmal gehabt — sein erster Wurf
     * fand die gesuchte Kopfzeile im eigenen Klassenkopf — und dort steht die
     * Lösung seit P6 Schritt 5g. Sie war da; sie stand nur an einer Stelle, an
     * die beim Bauen dieses Wächters niemand gesehen hat.
     *
     * > **Eine Lösung, die im Repo steht, ist nicht dieselbe wie eine, die
     * > angewandt wird.**
     *
     * `://` bleibt verschont: `https://claude.ai` ist kein Kommentar, und ein
     * Ausdruck, der jedes `//` frisst, macht daraus einen.
     */
    private function withoutComments(string $quelle): string
    {
        return (string) preg_replace(
            ['#<!--.*?-->#su', '#/\*.*?\*/#su', '#(^|\s)//(?![^\n]*://)[^\n]*#m'],
            ' ',
            $quelle,
        );
    }

    /**
     * Jede Vue-Datei mit ihrem Quelltext, abgelegt unter ihrem Seitennamen.
     *
     * Der Schlüssel ist der Name, unter dem Inertia die Seite rendert
     * (`Subscriptions/Show`), oder — für alles ausserhalb von `Pages/` — der
     * relative Pfad. Letztere sind die **Wurzeln**: Ein Layout und eine
     * Komponente stehen auf jeder Seite, also ist ein Link von dort ein Link,
     * den jeder erreicht.
     *
     * **Die ganze Datei und nicht nur der `<template>`-Block.** Eine Adresse
     * steht hier genauso oft in einem `router.get(...)` im Skriptteil wie in
     * einem `:href` — beides ist ein Weg zu dieser Seite.
     *
     * **Aber ohne Kommentare** ({@see self::withoutComments()}): Ein Erklärtext
     * nennt Adressen und führt nirgendwohin.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $wurzel = dirname(__DIR__, 2).'/resources/js';
        $dateien = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $relativ = str_replace($wurzel.'/', '', $file->getPathname());
            $schluessel = str_starts_with($relativ, 'Pages/')
                ? substr($relativ, strlen('Pages/'), -strlen('.vue'))
                : $relativ;

            $dateien[$schluessel] = $this->withoutComments((string) file_get_contents($file->getPathname()));
        }

        return $dateien;
    }

    /**
     * Rendert diese Controller-Methode eine Inertia-Seite?
     *
     * Gefragt wird die Methode und nicht die Klasse. `FileController` rendert
     * an drei Stellen und leitet an neun anderen weiter; die neun sind Griffe
     * und keine Seiten, und ein Wächter, der die Klasse fragt, hielte sie für
     * dasselbe.
     *
     * > **Ein Wächter, der die Klasse prüft, hat über die Methode nichts
     * > gesagt.** (`docs/53`, aus demselben Anlass wie `GuardReachTest`)
     */
    private function rendersPage(string $controller, string $method): ?string
    {
        $kurz = substr((string) strrchr('\\'.$controller, '\\'), 1);
        $pfad = dirname(__DIR__, 2).'/app/Http/Controllers/'.$kurz.'.php';

        if (! is_file($pfad)) {
            return null;
        }

        $quelle = (string) file_get_contents($pfad);

        // Vom Kopf der Methode bis zum Kopf der nächsten. Eine Klammerzählung
        // wäre genauer und hier unnötig: Zwischen zwei `public function` steht
        // genau ein Rumpf.
        if (preg_match(
            '/\n    (?:public|protected|private) function '.preg_quote($method, '/').'\(.*?(?=\n    (?:public|protected|private) function |\n}\s*$)/s',
            $quelle,
            $rumpf,
        ) !== 1) {
            return null;
        }

        // Der Name der Seite geht mit zurück und nicht nur ein „ja": Ohne ihn
        // wüsste der Lauf unten nicht, welche Datei diese Route *ist* — und
        // damit nicht, welcher Link ein Weg nach draussen ist und welcher ein
        // Verweis auf sich selbst.
        return preg_match("/Inertia::render\(\s*'([^']+)'/", $rumpf[0], $seite) === 1
            ? $seite[1]
            : null;
    }

    /**
     * Ein Ausdruck, der diese Adresse in einer Vue-Datei findet.
     *
     * `/subscriptions/{subscription}/files` wird zu
     * `/subscriptions/[^/'"`]*\/files`, und das trifft
     * `` `/subscriptions/${props.subscription.id}/files` `` ebenso wie
     * `"/subscriptions/1/files"`. Der Platzhalter darf alles ausser einem
     * Schrägstrich und einem Anführungszeichen enthalten — genau das
     * unterscheidet einen eingesetzten Wert von einem weiteren Pfadstück.
     */
    private function pattern(string $uri): string
    {
        $teile = array_map(
            static fn (string $stueck): string => str_starts_with($stueck, '{')
                ? '[^/\'"`]*'
                : preg_quote($stueck, '#'),
            explode('/', trim($uri, '/')),
        );

        // Kein `$` am Ende: `/files` und `/files/edit` sind verschiedene
        // Routen, aber `/files` steht auch am Anfang von `/files/edit` — der
        // Ausdruck bekommt deshalb eine Grenze, die kein Pfadzeichen ist.
        return '#/'.implode('/', $teile).'(?![\w/-])#';
    }

    public function test_every_page_is_reachable_from_a_template(): void
    {
        $quellen = $this->sources();

        /** @var list<array{name: string, uri: string, page: string}> $seiten */
        $seiten = [];

        foreach ($this->routes() as $route) {
            $seite = $route['verb'] === 'get'
                ? $this->rendersPage($route['controller'], $route['method'])
                : null;

            if ($seite !== null) {
                $seiten[] = ['name' => $route['name'], 'uri' => $route['uri'], 'page' => $seite];
            }
        }

        /*
         * **Der Lauf beginnt bei dem, was auf jeder Seite steht.**
         *
         * Layouts und Komponenten sind die Wurzeln — ein Link von dort erreicht
         * jeder. Alles unter `Pages/` ist erst dann eine Quelle, wenn die Seite
         * selbst erreicht wurde; sonst zählte eine Insel sich selbst als
         * Anleger, und genau das hat den ersten Wurf dieses Wächters seinen
         * eigenen Bruch überleben lassen.
         */
        $offen = array_keys(array_filter(
            $quellen,
            static fn (string $inhalt, string $schluessel): bool => str_ends_with($schluessel, '.vue'),
            ARRAY_FILTER_USE_BOTH,
        ));

        $erreichbar = [];
        $besucht = [];

        while ($offen !== []) {
            $quelle = array_shift($offen);

            if (isset($besucht[$quelle])) {
                continue;
            }

            $besucht[$quelle] = true;
            $text = $quellen[$quelle] ?? '';

            foreach ($seiten as $seite) {
                if (isset($erreichbar[$seite['name']]) || preg_match($this->pattern($seite['uri']), $text) !== 1) {
                    continue;
                }

                $erreichbar[$seite['name']] = true;

                // Die erreichte Seite wird selbst zur Quelle — von ihr aus
                // geht es weiter. Das ist der Unterschied zwischen „erwähnt"
                // und „erreichbar".
                $offen[] = $seite['page'];
            }
        }

        $geprueft = 0;

        foreach ($seiten as $seite) {
            $geprueft++;

            $this->assertTrue(
                isset($erreichbar[$seite['name']]),
                sprintf(
                    "Die Seite `%s` (%s, gerendert als `%s`) ist von der Navigation aus nicht\n".
                    "erreichbar.\n\n".
                    "Eine Seite, auf die nichts zeigt, ist nicht ausgeliefert — sie ist nur\n".
                    "vorhanden. Ein Link **von der Seite selbst** oder von einer anderen, die\n".
                    "genauso unerreichbar ist, zählt dabei nicht: Drei Seiten, die aufeinander\n".
                    "zeigen, sind eine Insel ohne Anleger.\n\n".
                    'Sie braucht einen Weg von aussen — ein `:href` oder ein `router.get` in '.
                    'einem Layout, einer Komponente oder einer Seite, die selbst erreichbar ist.',
                    $seite['name'],
                    $seite['uri'],
                    $seite['page'],
                ),
            );
        }

        /*
         * **Die Untergrenze, und sie zählt geprüfte Routen und keine Treffer.**
         *
         * Läuft der Ausdruck für Routen oder für Controller ins Leere, findet
         * dieser Wächter null Seiten und ist grün — genau die Falle, in die
         * dieses Vorgehen dreimal gelaufen ist. Die Zahl ist bewusst niedrig
         * gehalten: Sie soll melden, dass die Ausdrücke gebrochen sind, und
         * nicht beim Aufräumen zubeissen.
         */
        $this->assertGreaterThan(
            20,
            $geprueft,
            'Dieser Wächter hat fast keine Seite gefunden. Dann stimmt einer der beiden '.
            'Ausdrücke nicht mehr — der über `routes/web.php` oder der über die '.
            'Controller-Methoden —, und seine Zusage ist wertlos.',
        );
    }
}
