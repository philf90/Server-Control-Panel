<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Was der Server in fremder Sicht abweist, bietet die Seite dort nicht an.
 *
 * ## Der Befund
 *
 * `docs/144 §0` Befund 2, gemessen am 11. Oktober 2026 im Container: Ein
 * Betreiber wechselt über „Anmelden als" in die Sicht eines Kunden, und auf
 * „Mein Konto" stehen die API-Tokens mit Formular und „Entfernen". Ein
 * Klick auf „Marke anlegen" — so hiess der Knopf bis zum Abend desselben
 * Tages — gibt 403, und das Protokoll bucht
 * `api.token.created · denied` am Konto des Betreibers.
 *
 * Die Tür hält, und sie hält zu Recht. Falsch war der Satz daneben, in
 * `ProfileController::refuseWhileImpersonating()`: *„Die Oberfläche zeigt in
 * diesem Zustand kein Formular. Wer hier ankommt, hat die Anfrage selbst
 * gestellt."* Für „Konto" und „Passwort ändern" stimmte er, die Darstellung war
 * gesperrt — die API-Tokens kamen mit B7 danach.
 *
 * > **Eine Begründung, die ein Formular für abwesend erklärt, gilt für die
 * > Formulare, die es gab, als sie geschrieben wurde.**
 *
 * `AbilityReachTest` hält „ein Knopf, den der Betrachter nicht drücken darf,
 * wird nicht gezeigt" über die Fähigkeiten der Policy, und
 * `OperatorControlTest` dasselbe für Fähigkeiten, die strenger sind als die
 * der Seite. Die fremde Sicht ist keine Fähigkeit; keiner von beiden sah sie.
 *
 * ## Was hier gemessen wird
 *
 * **Die Handlungen kommen aus dem Controller und nicht aus einer Liste.** Jede
 * Methode eines Controllers, die `refuseWhileImpersonating()` ruft, ist eine
 * Handlung, die in fremder Sicht abgewiesen wird; `routes/web.php` sagt ihre
 * Methode und ihren Pfad. Auf der Seite, die derselbe Controller zeigt, wird
 * jedes Bedienelement gesucht, das diese Anfrage schickt — über einen Griff im
 * Skriptblock (`form.post('/…')`, `router.delete(…)`), den die Vorlage mit
 * `@submit` oder `@click` aufruft, über einen Aufruf direkt im Attribut
 * (`@submit.prevent="setup.post('/…')"`) oder über ein `<Link method="…">`.
 *
 * Jedes davon steht in fremder Sicht nicht da (`v-if="!props.impersonating"`
 * an ihm oder um es herum) oder ist dort gesperrt (`:disabled`, das
 * `impersonating` fragt). Und für jede Handlung muss es mindestens eines
 * geben — sonst greift der Ausdruck ins Leere, und Grün hiesse hier „nicht
 * gesucht".
 *
 * **Seit dem 14. Oktober 2026 kennt er auch eine abgewiesene Anzeige**
 * (`docs/144 §8`, Variante a). Der Controller des zweiten Faktors weist in
 * fremder Sicht schon das Zeigen seiner Seite ab. Für eine solche Handlung ist
 * das Bedienelement ein Verweis auf ihren Pfad, und der kann auf jeder Seite
 * stehen — gesucht wird deshalb in allen `.vue` und nicht nur auf den Seiten
 * des Controllers. Bis dahin stand „verwalten" auf „Mein Konto" in fremder
 * Sicht da, und dieser Wächter sah keine Handlung, an der er es hätte messen
 * können.
 *
 * Und eine Seite, deren Anzeige in fremder Sicht abgewiesen wird, bietet dort
 * nichts an: Ihre Bedienelemente gelten als nicht angeboten, wenn **jede**
 * Methode, die sie zeigt, abweist. Das ist kein Freibrief — gemessen wird es
 * am Controller, und eine Methode, die dieselbe Seite ohne Abweisung zeigt,
 * nimmt ihn zurück.
 *
 * ## Was er nicht kann
 *
 * Er fragt nur Handlungen, die abgewiesen werden. **Ob die Tür alles abweist,
 * was sie abweisen müsste, fragt er nicht** — ein Controller, der
 * `refuseWhileImpersonating()` nie ruft, kommt in seiner Liste gar nicht vor.
 * So stand der zweite Faktor bis zum 14. Oktober da, und das hält seitdem
 * `ImpersonationDoorTest` von der Seite der Tür.
 *
 * > **Ein Wächter, der die Abweisungen aus ihren Aufrufen liest, sieht keine
 * > Tür, die gar nicht abweist.**
 *
 * Und ob ein gesperrter Knopf richtig ist oder fehlen sollte, entscheidet er
 * nicht: Die Darstellung ist gesperrt und zeigt so die gewählte Fassung — das
 * ist eine Entscheidung und kein Mechanismus.
 */
final class ImpersonationControlTest extends TestCase
{
    use WithoutPhpComments;

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Controller, die in fremder Sicht etwas abweisen: je Methode, ob sie
     * abweist und welche Seiten sie zeigt.
     *
     * @return list<array{controller: string, methods: array<string, array{refuses: bool, pages: list<string>}>}>
     */
    private function controllers(): array
    {
        $aus = [];

        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app/Http/Controllers', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() !== 'php') {
                continue;
            }

            $quelle = $this->withoutComments((string) file_get_contents($datei->getPathname()));

            if (! str_contains($quelle, 'refuseWhileImpersonating(')) {
                continue;
            }

            preg_match_all('/\bfunction\s+(\w+)\s*\(/', $quelle, $methoden, PREG_OFFSET_CAPTURE);
            $je = [];

            foreach ($methoden[1] as $i => [$name, $start]) {
                if ($name === 'refuseWhileImpersonating') {
                    continue;
                }

                $ende = $methoden[1][$i + 1][1] ?? strlen($quelle);
                $rumpf = substr($quelle, $start, $ende - $start);

                preg_match_all("/Inertia::render\(\s*'([^']+)'/", $rumpf, $seiten);

                $je[$name] = [
                    'refuses' => str_contains($rumpf, '$this->refuseWhileImpersonating('),
                    'pages' => array_values(array_unique($seiten[1])),
                ];
            }

            $aus[] = ['controller' => $datei->getBasename('.php'), 'methods' => $je];
        }

        return $aus;
    }

    /**
     * Die Handlungen, die ein Controller in fremder Sicht abweist.
     *
     * `page` sind die Seiten des Controllers; `withheld` die davon, die er in
     * fremder Sicht gar nicht zeigt, weil jede Methode, die sie zeigt,
     * abweist.
     *
     * @return list<array{controller: string, method: string, page: list<string>, withheld: list<string>}>
     */
    private function refused(): array
    {
        $aus = [];

        foreach ($this->controllers() as $controller) {
            $seiten = [];
            $offen = [];

            foreach ($controller['methods'] as $methode) {
                foreach ($methode['pages'] as $seite) {
                    $seiten[$seite] = true;

                    if (! $methode['refuses']) {
                        $offen[$seite] = true;
                    }
                }
            }

            foreach ($controller['methods'] as $name => $methode) {
                if (! $methode['refuses']) {
                    continue;
                }

                $aus[] = [
                    'controller' => $controller['controller'],
                    'method' => $name,
                    'page' => array_keys($seiten),
                    'withheld' => array_keys(array_diff_key($seiten, $offen)),
                ];
            }
        }

        return $aus;
    }

    /**
     * Methode und Pfad einer Controllermethode, aus `routes/web.php`.
     *
     * @return array{verb: string, path: string}|null
     */
    private function route(string $controller, string $methode): ?array
    {
        $routen = $this->withoutComments((string) file_get_contents($this->root().'/routes/web.php'));

        $muster = sprintf(
            "/Route::(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[\s*%s::class\s*,\s*'%s'\s*\]/",
            preg_quote($controller, '/'),
            preg_quote($methode, '/'),
        );

        if (preg_match($muster, $routen, $treffer) !== 1) {
            return null;
        }

        return ['verb' => $treffer[1], 'path' => $this->normal($treffer[2])];
    }

    /** `{token}` und `${eintrag.id}` sind dieselbe Stelle eines Pfades. */
    private function normal(string $pfad): string
    {
        return (string) preg_replace('/\$?\{[^}]*\}/', '*', $pfad);
    }

    /**
     * Die Griffe des Skriptblocks: Name der Funktion => Anfragen, die sie schickt.
     *
     * @return array<string, list<string>>
     */
    private function handlers(string $sfc): array
    {
        $skript = preg_match('#<script\b[^>]*>(.*?)</script>#s', $sfc, $block) === 1 ? $block[1] : '';
        $griffe = [];

        preg_match_all('/\bfunction\s+(\w+)\s*\(/', $skript, $funktionen, PREG_OFFSET_CAPTURE);

        foreach ($funktionen[1] as $i => [$name, $start]) {
            $ende = $funktionen[1][$i + 1][1] ?? strlen($skript);
            $rumpf = substr($skript, $start, $ende - $start);

            preg_match_all('/\.(post|put|patch|delete)\(\s*([\'`])(.+?)\2/', $rumpf, $anfragen, PREG_SET_ORDER);

            foreach ($anfragen as $anfrage) {
                $griffe[$name][] = $anfrage[1].' '.$this->normal($anfrage[3]);
            }
        }

        return $griffe;
    }

    /**
     * Die Bedienelemente einer Vorlage, die eine Anfrage schicken: Offset und
     * Anfrage, je Element.
     *
     * Ein Verweis ohne Methode — `<Link href>` oder `<a href>` — schickt ein
     * GET; er zählt für eine abgewiesene Anzeige.
     *
     * @param  array<string, list<string>>  $griffe
     * @return list<array{offset: int, request: string}>
     */
    private function controls(string $vorlage, array $griffe): array
    {
        $aus = [];

        preg_match_all('/<([A-Za-z][\w-]*)((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)\/?>/s', $vorlage, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as $tag) {
            $attribute = $tag[2][0];
            $offset = (int) $tag[0][1];

            if (preg_match('/(?:@submit(?:\.prevent)?|@click)="(\w+)\b/', $attribute, $aufruf) === 1) {
                foreach ($griffe[$aufruf[1]] ?? [] as $anfrage) {
                    $aus[] = ['offset' => $offset, 'request' => $anfrage];
                }
            }

            if (preg_match('/(?:@submit(?:\.prevent)?|@click)="[^"]*?\.(post|put|patch|delete)\(\s*([\'`])(.+?)\2/', $attribute, $direkt) === 1) {
                $aus[] = ['offset' => $offset, 'request' => $direkt[1].' '.$this->normal($direkt[3])];
            }

            if (! in_array($tag[1][0], ['Link', 'a'], true) || preg_match('/\b:?href="`?([^"`]+)`?"/', $attribute, $ziel) !== 1) {
                continue;
            }

            if (preg_match('/\bmethod="(post|put|patch|delete)"/', $attribute, $methode) === 1) {
                if ($tag[1][0] === 'Link') {
                    $aus[] = ['offset' => $offset, 'request' => $methode[1].' '.$this->normal($ziel[1])];
                }

                continue;
            }

            $aus[] = ['offset' => $offset, 'request' => 'get '.$this->normal($ziel[1])];
        }

        return $aus;
    }

    /**
     * Steht das Element an dieser Stelle in fremder Sicht nicht da oder ist es
     * dort gesperrt?
     *
     * **Ein Stapel und keine Rückwärtssuche**, wie in `OperatorControlTest`:
     * Vorwärts gelesen ist jedes offene Element bekannt, wenn die Fundstelle
     * kommt.
     */
    private function withheld(string $vorlage, int $offset): bool
    {
        $verborgen = '/\bv-if="[^"]*!\s*(?:props\.)?impersonating\b[^"]*"/';
        $gesperrt = '/\s:disabled="[^"]*\b(?:props\.)?impersonating\b[^"]*"/';
        $leer = ['input', 'img', 'br', 'hr', 'meta', 'link', 'source', 'col'];
        $stapel = [];

        preg_match_all('/<(\/?)([A-Za-z][\w-]*)((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/s',
            $vorlage, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($tags as $tag) {
            $pos = (int) $tag[0][1];
            [, $schluss, $name, $attribute, $selbst] = array_column($tag, 0);

            if ($pos === $offset) {
                return preg_match($verborgen, $attribute) === 1
                    || preg_match($gesperrt, $attribute) === 1
                    || array_filter($stapel, static fn (string $a): bool => preg_match($verborgen, $a) === 1) !== [];
            }

            if ($pos > $offset) {
                break;
            }

            if ($schluss === '/') {
                array_pop($stapel);

                continue;
            }

            if ($selbst === '/' || in_array(strtolower($name), $leer, true)) {
                continue;
            }

            $stapel[] = $attribute;
        }

        return false;
    }

    private function template(string $sfc): string
    {
        $vorlage = preg_match('/^<template\b.*^<\/template>/ms', $sfc, $block) === 1 ? $block[0] : '';

        return (string) preg_replace('/<!--.*?-->/s', '', $vorlage);
    }

    /**
     * Jede `.vue` unter `resources/js`, Pfad relativ dazu.
     *
     * @return list<string>
     */
    private function components(): array
    {
        $aus = [];

        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/resources/js', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() === 'vue') {
                $aus[] = substr($datei->getPathname(), strlen($this->root().'/resources/js/'));
            }
        }

        sort($aus);

        return $aus;
    }

    public function test_every_refused_action_is_withheld_in_foreign_view(): void
    {
        $handlungen = $this->refused();

        /*
         * **Die Untergrenze zählt mit.** Gezählt am 11. Oktober 2026: fünf
         * Handlungen in `ProfileController` — Konto, Passwort, Darstellung,
         * API-Token anlegen, API-Token entfernen. Seit dem 14. Oktober drei
         * mehr in `TwoFactorSetupController`: anzeigen, einschalten,
         * abschalten.
         */
        $this->assertGreaterThanOrEqual(8, count($handlungen),
            'Es werden kaum Handlungen gefunden, die in fremder Sicht abgewiesen werden — dann greift der '.
            'Ausdruck über die Controller ins Leere, und jeder Befund hier wäre ein Freispruch.');

        $offen = [];

        foreach ($handlungen as $handlung) {
            $route = $this->route($handlung['controller'], $handlung['method']);

            if ($route === null) {
                $offen[] = sprintf('%s::%s — keine Route in routes/web.php gefunden', $handlung['controller'], $handlung['method']);

                continue;
            }

            $anfrage = $route['verb'].' '.$route['path'];
            $gefunden = 0;

            /*
             * Eine abgewiesene Anzeige erreicht man über einen Verweis, und
             * der kann auf jeder Seite stehen — eine abgewiesene Änderung über
             * ein Bedienelement auf der Seite, die derselbe Controller zeigt.
             */
            $dateien = $route['verb'] === 'get'
                ? $this->components()
                : array_map(static fn (string $seite): string => 'Pages/'.$seite.'.vue', $handlung['page']);

            foreach ($dateien as $datei) {
                $sfc = (string) file_get_contents($this->root().'/resources/js/'.$datei);
                $vorlage = $this->template($sfc);
                $seite = (string) preg_replace('#^Pages/(.+)\.vue$#', '$1', $datei);

                foreach ($this->controls($vorlage, $this->handlers($sfc)) as $element) {
                    if ($element['request'] !== $anfrage) {
                        continue;
                    }

                    $gefunden++;

                    if (in_array($seite, $handlung['withheld'], true) || $this->withheld($vorlage, $element['offset'])) {
                        continue;
                    }

                    $offen[] = $route['verb'] === 'get'
                        ? sprintf('%s · %s — ein Verweis auf „%s" steht in fremder Sicht da, und die Seite dahinter weist dort ab',
                            $datei, $handlung['method'], $route['path'])
                        : sprintf('%s · %s — ein Bedienelement für „%s" steht in fremder Sicht da',
                            $seite, $handlung['method'], $anfrage);
                }
            }

            if ($gefunden === 0) {
                $offen[] = sprintf('%s::%s — kein Bedienelement für „%s" auf %s gefunden; dann misst dieser Wächter hier nichts',
                    $handlung['controller'], $handlung['method'], $anfrage,
                    $route['verb'] === 'get' ? 'einer Seite unter resources/js' : implode(', ', $handlung['page']));
            }
        }

        $this->assertSame([], $offen, sprintf(
            "Der Server weist diese Handlungen in fremder Sicht ab, und die Seite bietet sie dort an:\n  %s\n\n".
            'Ein Klick darauf endet mit 403, und das Protokoll bucht ihn als selbst gestellten Versuch '.
            '(RefusesWhileImpersonating). Das Bedienelement gehört in ein '.
            '`v-if="!props.impersonating"` oder bekommt ein `:disabled`, das `impersonating` fragt.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Die Prüfkörper sind hier selbst geschrieben und keine Datei des Repos —
     * eine Datei, die heute so aussieht, sieht morgen anders aus. Beide
     * Richtungen in einem Fall: verborgen, gesperrt, und offen.
     */
    public function test_the_reader_tells_withheld_from_offered(): void
    {
        $vorlage = <<<'VUE'
            <template>
              <Section v-if="!props.impersonating" title="A">
                <form @submit.prevent="eins"></form>
              </Section>
              <button type="button" :disabled="x.processing || props.impersonating" @click="zwei(1)">Zwei</button>
              <form class="form" @submit.prevent="drei"></form>
            </template>
            VUE;

        $griffe = ['eins' => ['post /a'], 'zwei' => ['put /b'], 'drei' => ['delete /c/*']];
        $urteil = [];

        foreach ($this->controls($vorlage, $griffe) as $element) {
            $urteil[$element['request']] = $this->withheld($vorlage, $element['offset']);
        }

        $this->assertSame(['post /a' => true, 'put /b' => true, 'delete /c/*' => false], $urteil);
    }

    /**
     * Die beiden Formen, die der Leser seit dem 14. Oktober kennt: ein
     * Verweis ohne Methode, verborgen und offen, und ein Aufruf direkt im
     * Attribut. Ein Verweis mit Methode bleibt, was er war, und schickt kein
     * GET.
     */
    public function test_the_reader_finds_links_and_inline_calls(): void
    {
        $vorlage = <<<'VUE'
            <template>
              <table class="pairs">
                <tr>
                  <td v-if="!props.impersonating" class="right"><Link href="/a" class="link">verwalten</Link></td>
                  <td class="right"><a href="/b">ansehen</a></td>
                </tr>
              </table>
              <form @submit.prevent="setup.post('/c')"></form>
              <Link href="/d" method="delete" as="button">Weg</Link>
            </template>
            VUE;

        $urteil = [];

        foreach ($this->controls($vorlage, []) as $element) {
            $urteil[$element['request']] = $this->withheld($vorlage, $element['offset']);
        }

        $this->assertSame(['get /a' => true, 'get /b' => false, 'post /c' => false, 'delete /d' => false], $urteil);
    }
}
