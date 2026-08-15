<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Zwei Bausteine, die beide nichts mitbringen, kleben aneinander.
 *
 * ## Der fünfte Anlauf, und diesmal ist die Frage richtig gestellt
 *
 * Hier hiess der Wächter `ButtonRowSpacingTest`, und er fragte nach
 * **Knopfreihen**. Das war die Frage der ersten vier Fälle und trotzdem die
 * falsche: Beim fünften stand unter der Blätterleiste eine **Meldung** —
 * dieselbe Fuge, ein anderer Nachfolger, und der Wächter sah nichts.
 *
 * > **Eine Liste von Nachbarn, die wächst, ist keine Regel — sie ist eine
 * > Aufzählung der Fälle, die schon jemand gesehen hat.**
 *
 * Die Frage lautet jetzt: **Endet der eine bündig, und fängt der andere bündig
 * an?** Zwei Listen, alle Paare daraus, und für jedes Paar, das in einer Vorlage
 * wirklich vorkommt, muss `app.css` eine Nachbarschaftsregel haben.
 *
 * ## Und der Ausdruck hat einen seiner eigenen Bausteine nie gefunden
 *
 * Der alte suchte den Vorgänger mit `<(\w+)…>(?:(?!<\/?\1[\s>]).)*<\/\1>` — also
 * „bis zum nächsten Tag desselben Namens". Für `.scrolls` geht das; **für
 * `.pager` nicht**, denn darin stehen drei `<div>`. `pager` stand seit Schritt 5
 * in der Liste und ist **nie getroffen worden**; die Untergrenze zählte die
 * anderen mit und blieb grün.
 *
 * > **Ein Eintrag in einer Liste, den der Ausdruck nie erreicht, sieht aus wie
 * > eine Abdeckung und ist eine Lücke.**
 *
 * Gesucht wird deshalb über die **Verschachtelungstiefe**: vom öffnenden Tag des
 * Vorgängers vorwärts zählen, bis er zu ist, und dann das nächste öffnende Tag
 * ansehen. Das versteht auch einen Baustein mit Kindern.
 */
final class BlockSpacingTest extends TestCase
{
    /**
     * Bausteine, deren Luft aus ihrem Padding kommt und nicht aus einem Rand.
     *
     * **Padding trennt zwei Kästen nicht — ausser der Kasten ist unsichtbar.**
     * `.notice` und `.cell-value` haben beide reichlich Padding und zeichnen
     * beide einen Rahmen mit Fläche; ihre Kante klebt trotzdem am Nachbarn, und
     * das Padding schiebt nur den Inhalt nach innen. `.empty` zeichnet nichts,
     * und dort sind die 22px oben und unten echter Abstand.
     *
     * Wer hier etwas einträgt, behauptet: Dieser Baustein hat keine sichtbare
     * Kante. Das ist eine Aussage über `background` und `border` und keine über
     * Geschmack.
     *
     * @var list<string>
     */
    private const HAS_OWN_AIR = ['empty'];

    /**
     * Fugen, die noch niemand angesehen hat.
     *
     * **Sie sind keine Ausnahme, sondern eine Zahl, die kleiner werden soll.**
     * Als dieser Wächter am 14. August 2026 von zwei gepflegten Listen auf die
     * Ableitung aus `app.css` umgestellt wurde, kamen dreissig Fugen zum
     * Vorschein, die vorher gar nicht in seinem Blick lagen. Sie hier
     * einzutragen ist ehrlicher als die Listen so klein zu lassen, dass sie
     * nicht auffallen:
     *
     * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
     * > kleiner werden kann.** (`CLAUDE.md`)
     *
     * Jede davon gehört unter die Bilderrunde aus Schritt 12: Ob zwei Bausteine
     * zu eng stehen, entscheidet ein Blick und keine Regel. Was dabei
     * herauskommt, ist entweder eine Nachbarschaftsregel in `app.css` oder die
     * Erkenntnis, dass die beiden gar nicht untereinander liegen — und dann
     * fällt der Eintrag ersatzlos weg.
     *
     * **Neue Fugen kommen hier nicht dazu.** Eine, die dieser Wächter findet
     * und die nicht dasteht, ist rot; das ist der ganze Zweck der Liste.
     *
     * **Am 15. August sind sechs dazugekommen und drei weggefallen**, ohne dass
     * sich eine Vorlage geändert hätte: Der Wächter sieht seitdem durch ein
     * `v-if` hindurch (die sechs) und behandelt einen benannten Platz als
     * Behälter statt als Luft (die drei). Beides sind Korrekturen an ihm selbst
     * und keine an der Gestaltung.
     *
     * @var list<string>
     */
    private const OPEN_SEAMS = [
        'arrow + label',
        'button + button',
        'button-row + button',
        'button-row + form',
        'choices + dependent',
        'choices + label',
        'choices + with-unit',
        'dependent + dependent',
        'dependent + with-unit',
        'field + button',
        'form + form',
        'field + field-row',
        'hint + field-row',
        'hint + form',
        'scrolls + form',
        'ident + ident',
        'ident + notice',
        'link + link',
        'output + button-row',
        'pager-state + button',
        'section-note + notice',
        'section-note + button-row',
        'section-note + cell-value',
        'section-note + scrolls',
        'sections + button-row',
        'sections + notice',
        'toggle + button-row',
        'toggle + choices',
        'toggle + dependent',
        'toggle + with-unit',
        'with-unit + dependent',
    ];

    /**
     * Was `app.css` über jede Klasse sagt: Rand oben, Rand unten, Richtung.
     *
     * **Der sechste Fall hat diesen Wächter hierher gebracht.** Bis dahin
     * standen zwei Listen im Quelltext, von Hand gepflegt — und `.crumbs` kam
     * in P6 dazu, ohne dass jemand daran dachte. Die Liste, die eine Liste von
     * Nachbarpaaren abgelöst hatte, war selbst wieder eine.
     *
     * > **Eine Liste, die von Hand gepflegt wird, ist beim nächsten Zuwachs
     * > unvollständig — auch dann, wenn sie schon die Verbesserung einer
     * > schlechteren Liste war.**
     *
     * Gelesen werden nur Regeln der obersten Ebene mit **einem einfachen
     * Klassenselektor**. Ein `@media`-Block ändert Ränder erst ab einer Breite,
     * und ein `.a .b` beschreibt eine Lage und keinen Baustein; beides gehört
     * nicht in eine Aussage darüber, was ein Baustein von sich aus mitbringt.
     *
     * @return array<string, array{top: bool, bottom: bool, row: bool|null, gap: bool}>
     */
    private function stylesheet(): array
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        preg_match_all('/(^|\n)([^{}@\n][^{}]*?)\{([^{}]*)\}/s', $css, $regeln, PREG_SET_ORDER);

        $gesetzt = static fn (string $v): bool => ! in_array(trim($v), ['0', '0px', 'auto', ''], true);

        /** @return array{0: bool, 1: bool} */
        $kurzform = static function (string $v) use ($gesetzt): array {
            $teile = preg_split('/\s+/', trim($v)) ?: [''];

            // `margin: a`, `margin: a b` — oben und unten sind dasselbe.
            // `margin: a b c`, `margin: a b c d` — unten steht an dritter Stelle.
            return count($teile) <= 2
                ? [$gesetzt($teile[0]), $gesetzt($teile[0])]
                : [$gesetzt($teile[0]), $gesetzt($teile[2])];
        };

        $klassen = [];

        foreach ($regeln as $regel) {
            if (preg_match('/^\.([\w-]+)$/', trim($regel[2]), $name) !== 1) {
                continue;
            }

            $k = $klassen[$name[1]] ?? ['top' => false, 'bottom' => false, 'row' => null, 'gap' => false];

            if (preg_match('/(?:^|;)\s*margin\s*:\s*([^;]+)/', $regel[3], $x) === 1) {
                [$k['top'], $k['bottom']] = $kurzform($x[1]);
            }

            if (preg_match('/margin-top\s*:\s*([^;]+)/', $regel[3], $x) === 1) {
                $k['top'] = $gesetzt($x[1]);
            }

            if (preg_match('/margin-bottom\s*:\s*([^;]+)/', $regel[3], $x) === 1) {
                $k['bottom'] = $gesetzt($x[1]);
            }

            /*
             * **Zwei Gründe, warum zwischen zwei Kindern keine Fuge ist.**
             *
             * Der erste: Sie liegen **nebeneinander** — ein Flexkasten in der
             * Waagerechten. Dann gibt es senkrecht gar nichts zu trennen.
             *
             * Der zweite: Der Elternteil setzt selbst ein `gap`. Das hat
             * dieser Wächter beim ersten Wurf übersehen und prompt in einem
             * neuen Baustein gemeldet, dessen Abstände vollständig aus dem
             * `gap` seines Elternteils kommen (`.permissions`, P6 Schritt 5c).
             *
             * > **Ein Abstand, der aus dem Elternteil kommt, ist genauso ein
             * > Abstand.**
             *
             * Gelesen wird der **erste** Wert von `gap`: `gap: 4px 22px` heisst
             * 4px senkrecht und 22px waagerecht, und nur der erste trennt zwei
             * gestapelte Kinder.
             */
            if (preg_match('/display\s*:\s*(?:inline-)?(?:flex|grid)/', $regel[3]) === 1) {
                $k['row'] = preg_match('/flex-direction\s*:\s*column/', $regel[3]) !== 1;

                if (preg_match('/(?:^|;)\s*(?:row-)?gap\s*:\s*([^;]+)/', $regel[3], $x) === 1) {
                    $k['gap'] = $gesetzt((preg_split('/\s+/', trim($x[1])) ?: [''])[0]);
                }
            }

            $klassen[$name[1]] = $k;
        }

        return $klassen;
    }

    /**
     * Die Bausteine, die unten bündig enden und oben bündig anfangen.
     *
     * Abgeleitet und nicht aufgezählt — siehe {@see self::stylesheet()}.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function flush(): array
    {
        $endet = [];
        $faengt = [];

        foreach ($this->stylesheet() as $name => $kanten) {
            if (in_array($name, self::HAS_OWN_AIR, true)) {
                continue;
            }

            if (! $kanten['bottom']) {
                $endet[] = $name;
            }

            if (! $kanten['top']) {
                $faengt[] = $name;
            }
        }

        return [$endet, $faengt];
    }

    /** @return list<string> */
    private function templates(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }

    /**
     * Ein ganzer Klassenname in einer Klassenliste.
     *
     * **`\b` ist dafür die falsche Grenze.** `\bpager\b` trifft `pager-state`,
     * `\bcell\b` trifft `cell-value` — beide Klassen gibt es in `app.css`, und
     * beide sind etwas anderes als der Baustein, nach dem gefragt wird.
     * Aufgefallen beim Bruch: Umbenennen von `sections` nach `sections-x` liess
     * den Wächter grün.
     *
     * > **Ein Bindestrich ist für einen regulären Ausdruck eine Wortgrenze und
     * > für eine Klassenliste keine.**
     */
    private function hasClass(string $attributes, string $name): bool
    {
        if (preg_match('/class="([^"]*)"/', $attributes, $treffer) !== 1) {
            return false;
        }

        return in_array($name, preg_split('/\s+/', trim($treffer[1])) ?: [], true);
    }

    /**
     * Der `<template>`-Block einer Vorlage, gerendert gedacht.
     *
     * **Zwei Dinge passieren hier, und beide sind Fehler des ersten Wurfs.**
     *
     * 1. **Nur der `<template>`-Block.** Über die ganze Datei gelesen, sieht ein
     *    TypeScript-Generic wie ein Tag aus: `ref<HTMLElement | null>` liefert
     *    ein `<HTMLElement …>`, das nie zugeht. Die Tiefenzählung lief damit ins
     *    Dateiende, und die Suche fand fast nichts — sie meldete das nicht,
     *    sondern gab weniger Paare zurück.
     *
     * 2. **`<template>` selbst fällt weg.** Ein `<template v-else>` rendert
     *    nichts; seine Kinder stehen an seiner Stelle. Im Quelltext sind die
     *    Blätterleiste und die Meldung darunter deshalb **keine** Geschwister —
     *    im Browser sind sie es, und dort klebten sie. Genau dieser Fall ist der
     *    Anlass dieses Wächters.
     *
     * > **Ein Wächter, der Markup liest, muss lesen, was gerendert wird — nicht,
     * > was dasteht.**
     */
    private function rendered(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $treffer) !== 1) {
            return '';
        }

        /*
         * **Ein benannter Platz ist ein Behälter und kein `v-if`.**
         *
         * Beide heissen `<template>`, und sie sind das Gegenteil voneinander:
         * Die Kinder eines `<template v-if>` stehen an seiner Stelle, die
         * Kinder eines `<template #actions>` stehen ganz woanders — das Layout
         * setzt sie in seine Kopfzeile.
         *
         * Wer beide gleich behandelt, macht den letzten Knopf aus `#actions`
         * zum Nachbarn dessen, was im Quelltext darunter steht. Gemessen, als
         * dieser Wächter durch `v-if` hindurchsehen lernte: drei der neun neu
         * gefundenen Fugen waren solche Scheinnachbarn.
         *
         * > **Zwei Dinge, die im Quelltext gleich heissen, sind im Browser
         * > nicht dasselbe.**
         *
         * Der benannte Platz wird deshalb zu einem gewöhnlichen Kasten — dann
         * bleiben seine Kinder unter sich.
         */
        $ohneKommentare = (string) preg_replace('/<!--.*?-->/su', '', $treffer[1]);

        /*
         * **Über einen Stapel und nicht über einen Ausdruck.** Welches
         * `</template>` zu welchem `<template …>` gehört, kann ein regulärer
         * Ausdruck nicht entscheiden — und in einem benannten Platz steht
         * regelmässig ein `<template v-if>`.
         */
        $tiefe = [];

        return (string) preg_replace_callback(
            '~<template([^>]*)>|</template>~s',
            static function (array $treffer) use (&$tiefe): string {
                if ($treffer[0] === '</template>') {
                    return array_pop($tiefe) === true ? '</div>' : '';
                }

                $benannt = preg_match('~(^|\s)(#|v-slot)~', $treffer[1]) === 1;
                $tiefe[] = $benannt;

                return $benannt ? '<div>' : '';
            },
            $ohneKommentare,
        );
    }

    /**
     * Das nächste Geschwister eines öffnenden Tags, oder `null`.
     *
     * Gezählt wird über die **Verschachtelungstiefe**: vom öffnenden Tag
     * vorwärts, bis es zu ist, und dann das nächste öffnende Tag. Das versteht
     * auch einen Baustein mit Kindern — der alte Ausdruck las „bis zum nächsten
     * Tag desselben Namens" und übersah damit jeden, der welche hat.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $tags
     * @param  list<string>  $void
     */
    private function sibling(array $tags, int $index, array $void): ?int
    {
        $tiefe = 1;
        $i = $index + 1;

        for (; $i < count($tags) && $tiefe > 0; $i++) {
            $folgend = $tags[$i];

            if ($folgend[1] === '/') {
                $tiefe--;

                continue;
            }

            if (! in_array(strtolower($folgend[2]), $void, true) && ! str_ends_with(rtrim($folgend[3]), '/')) {
                $tiefe++;
            }
        }

        // `$i` steht hinter dem schliessenden Tag. Ein `</div>` dort heisst:
        // Der Vorgänger war das letzte Kind, und dann gibt es kein Geschwister.
        return isset($tags[$i]) && $tags[$i][1] !== '/' ? $i : null;
    }

    /**
     * Alle Paare aus „Baustein" und „was unmittelbar danach kommt".
     *
     * **Über die Verschachtelungstiefe und nicht über den Tagnamen.** Der alte
     * Ausdruck las bis zum nächsten Tag desselben Namens und übersah damit jeden
     * Baustein mit Kindern — siehe Klassenkopf.
     *
     * Elemente ohne Ende (`<input>`) und selbstschliessende (`<Foo />`) zählen
     * nicht in die Tiefe; ohne das käme der Zähler nie zurück auf null, und die
     * Suche endete stumm am Dateiende.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(string $template): array
    {
        $void = ['input', 'br', 'hr', 'img', 'meta', 'link', 'source', 'area', 'col'];

        preg_match_all('/<(\/?)([a-zA-Z][\w.-]*)([^>]*)>/s', $template, $tags, PREG_SET_ORDER);

        [$endetBuendig, $faengtBuendig] = $this->flush();
        $stil = $this->stylesheet();

        /*
         * **Der Elternteil je Tag, über einen Stapel.**
         *
         * Ohne ihn zählt dieser Wächter jede Knopfreihe als Fuge: Zwei Knöpfe
         * nebeneinander sind Geschwister, und ein Rand zwischen ihnen wäre
         * waagerecht und damit die falsche Frage. Gemessen, als die Listen auf
         * die Ableitung umgestellt wurden: **118 von 148 Paaren** liegen
         * nebeneinander.
         *
         * > **Zwei Kästen, die nebeneinander stehen, haben keine Fuge — sie
         * > haben eine Lücke, und die macht `gap`.**
         */
        $stapel = [];
        $eltern = [];

        foreach ($tags as $index => $tag) {
            if ($tag[1] === '/') {
                array_pop($stapel);

                continue;
            }

            $eltern[$index] = end($stapel) ?: '';

            if (! in_array(strtolower($tag[2]), $void, true) && ! str_ends_with(rtrim($tag[3]), '/')) {
                $stapel[] = $tag[3];
            }
        }

        $paare = [];

        foreach ($tags as $start => $tag) {
            if ($tag[1] === '/' || in_array(strtolower($tag[2]), $void, true) || str_ends_with(rtrim($tag[3]), '/')) {
                continue;
            }

            $ohneFuge = false;

            foreach ($stil as $klasse => $kanten) {
                if (! $this->hasClass($eltern[$start] ?? '', $klasse)) {
                    continue;
                }

                if ($kanten['row'] === true || ($kanten['row'] !== null && $kanten['gap'])) {
                    $ohneFuge = true;

                    break;
                }
            }

            if ($ohneFuge) {
                continue;
            }

            /*
             * **Ein `v-if` ist Nachbar und Luft zugleich.**
             *
             * Der dritte Fall derselben Familie in diesem Wächter, nach dem
             * TypeScript-Generic und dem `<template v-else>`. Seit P6 Schritt 5c
             * steht zwischen dem Formular und den Brotkrumen im Dateimanager ein
             * `<form v-if="chmodFor !== null">` **ohne Klasse**. Im Quelltext ist
             * es der Nachbar; im Browser ist es meistens gar nicht da, und dann
             * berühren sich die beiden dahinter.
             *
             * Gemerkt hat es der Bruchlauf in der CI: Der Eingriff, der die Fuge
             * wieder aufreisst, liess den Wächter grün.
             *
             * > **Ein Wächter, der ein `v-if` für vorhanden hält, liest ein
             * > Markup, das es so nie gibt.**
             *
             * Gesammelt werden deshalb **alle** Nachbarn, die entstehen können:
             * das nächste Geschwister, und — solange dieses an einer Bedingung
             * hängt — auch das dahinter.
             */
            $nachbarn = [];
            $naechster = $this->sibling($tags, $start, $void);

            while ($naechster !== null) {
                $nachbarn[] = $tags[$naechster];

                if (preg_match('/\sv-(?:if|else-if|else)\b/', $tags[$naechster][3]) !== 1) {
                    break;
                }

                $naechster = $this->sibling($tags, $naechster, $void);
            }

            foreach ($nachbarn as $nachbar) {
                foreach ($endetBuendig as $unten) {
                    foreach ($faengtBuendig as $oben) {
                        if ($this->hasClass($tag[3], $unten) && $this->hasClass($nachbar[3], $oben)) {
                            $paare[] = [$unten, $oben];
                        }
                    }
                }
            }
        }

        return $paare;
    }

    /**
     * Eine Selektorliste in ihre Glieder, an den Kommas der obersten Ebene.
     *
     * **`explode(',', …)` reicht dafür nicht**, und das hat beim Umbau eine
     * Runde gekostet: `:is(.field, .hint, .error, .scrolls, .pager, .cell-value)
     * + .button-row` ist **ein** Selektor mit fünf Kommas darin. Wer ihn an
     * jedem Komma zerteilt, bekommt sechs Bruchstücke, von denen nur das letzte
     * ein `+` trägt — und verliert damit fünf Nachbarschaften auf einmal.
     *
     * > **Ein Komma in einer Klammer trennt etwas anderes als eines
     * > daneben.**
     *
     * @return list<string>
     */
    private function selectors(string $liste): array
    {
        $glieder = [];
        $laufend = '';
        $tiefe = 0;

        foreach (str_split($liste) as $zeichen) {
            if ($zeichen === '(') {
                $tiefe++;
            } elseif ($zeichen === ')') {
                $tiefe--;
            } elseif ($zeichen === ',' && $tiefe === 0) {
                $glieder[] = $laufend;
                $laufend = '';

                continue;
            }

            $laufend .= $zeichen;
        }

        $glieder[] = $laufend;

        return $glieder;
    }

    /**
     * Die Nachbarschaften, die `app.css` kennt.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function covered(): array
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        /*
         * **Erst der Selektor, dann seine Glieder.**
         *
         * Hier stand ein Ausdruck über die ganze Selektorliste, und er hat beim
         * ersten Zuwachs eine Nachbarschaft verloren: `.a + .b,\n.a + .c {`
         * enthält zwei `+`, und der Ausdruck konnte nur das letzte Paar sehen —
         * `.button-row + .sections` fiel lautlos aus der Abdeckung, in dem
         * Moment, in dem `.button-row + .crumbs` danebengeschrieben wurde.
         *
         * > **Ein Ausdruck über eine Liste, der nur ein Glied trifft, meldet
         * > nicht, dass er die anderen nicht gesehen hat.**
         *
         * Gemerkt hat es der Wächter selbst, weil er die drei Fugen aus seiner
         * eigenen Geschichte namentlich nachrechnet.
         */
        preg_match_all('/([^{}]+)\{/s', $css, $regeln, PREG_SET_ORDER);

        $paare = [];

        foreach ($regeln as $regel) {
            foreach ($this->selectors($regel[1]) as $selektor) {
                if (! str_contains($selektor, '+')) {
                    continue;
                }

                $glieder = explode('+', $selektor);

                for ($i = 1; $i < count($glieder); $i++) {
                    preg_match_all('/\.([\w-]+)/', $glieder[$i - 1], $links);
                    preg_match_all('/\.([\w-]+)/', $glieder[$i], $rechts);

                    foreach ($links[1] as $a) {
                        foreach ($rechts[1] as $b) {
                            $paare[] = [$a, $b];
                        }
                    }
                }
            }
        }

        return $paare;
    }

    public function test_the_rule_still_lists_what_it_used_to(): void
    {
        $abgedeckt = $this->covered();

        foreach ([['scrolls', 'button-row'], ['field', 'button-row'], ['button-row', 'sections']] as $muss) {
            $this->assertContains(
                $muss,
                $abgedeckt,
                sprintf(
                    'app.css kennt die Nachbarschaft `.%s + .%s` nicht mehr. Das ist eine der Fugen, '.
                    'die dieser Wächter festhält — ohne sie klebt der eine Baustein am anderen.',
                    $muss[0],
                    $muss[1],
                ),
            );
        }
    }

    /**
     * Jede Fuge zwischen zwei bündigen Bausteinen ist erfasst.
     *
     * **Fünf Fälle in fünf Schritten, jeder vom Betreiber auf einem Bild
     * gefunden** — keiner von einer Messung, denn nichts läuft dabei über und
     * nichts ist abgeschnitten. Es sieht nur gedrängt aus.
     *
     * Der fünfte war eine Meldung unter der Blätterleiste, und er hat die Frage
     * dieses Wächters umgestellt: nicht mehr „wo steht eine Knopfreihe?",
     * sondern „welche zwei bündigen Bausteine stehen aneinander?"
     */
    public function test_every_seam_between_two_flush_blocks_is_covered(): void
    {
        $abgedeckt = $this->covered();
        $gesehen = [];
        $offen = [];

        foreach ($this->templates() as $path) {
            $template = $this->rendered((string) file_get_contents($path));

            foreach ($this->pairs($template) as $paar) {
                $gesehen[implode(' + ', $paar)] = true;

                if (in_array($paar, $abgedeckt, true)) {
                    continue;
                }

                $name = implode(' + ', $paar);
                $offen[$name] ??= [];
                $offen[$name][$this->relative($path)] = true;
            }
        }

        foreach ($offen as $name => $wo) {
            [$unten, $oben] = explode(' + ', $name);

            $this->assertContains(
                $name,
                self::OPEN_SEAMS,
                sprintf(
                    "%s setzt `.%s` unmittelbar unter `.%s`, und app.css kennt diese Nachbarschaft\n".
                    "nicht.\n\n".
                    "`.%s` endet bündig und `.%s` fängt bündig an — die beiden kleben dann\n".
                    "aneinander. Die Nachbarschaft gehört in `app.css`; ein Abstand auf der Seite\n".
                    "wäre derselbe Fehler wie ein Hexwert in einer Komponente.\n\n".
                    'Liegen die beiden in Wahrheit nebeneinander, fehlt ihrem Elternteil in '.
                    '`app.css` das `display: flex` — und dann ist das hier ein Fund über das '.
                    'Stylesheet und nicht über diese Vorlage.',
                    implode(', ', array_keys($wo)),
                    $oben,
                    $unten,
                    $unten,
                    $oben,
                ),
            );
        }

        /*
         * **Und die Sperrklinke in die andere Richtung.**
         *
         * Ein Eintrag in {@see self::OPEN_SEAMS}, den es nicht mehr gibt, ist
         * dasselbe wie ein Eintrag in einer Positivliste, der ins Leere zeigt:
         * Er sieht aus wie ein bekanntes Loch und ist keins mehr. Ohne diese
         * Richtung wächst die Liste nie wieder nach unten, weil niemand daran
         * denkt, sie zu kürzen.
         *
         * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl,
         * > die kleiner werden kann.** Sie kann es aber nur, wenn jemand es
         * > merkt.
         */
        foreach (self::OPEN_SEAMS as $bekannt) {
            $this->assertArrayHasKey(
                $bekannt,
                $offen,
                sprintf(
                    "`%s` steht in BlockSpacingTest::OPEN_SEAMS und kommt nicht mehr vor.\n\n".
                    'Entweder ist die Fuge geschlossen — dann gehört die Zeile gelöscht — oder die '.
                    'Suche findet sie nicht mehr, und dann ist der Wächter kaputt.',
                    $bekannt,
                ),
            );
        }

        /*
         * **Die Untergrenze zählt verschiedene Paare und nicht Vorkommen.**
         * Hier stand vorher eine Zahl über die Treffer, und die war erfüllt,
         * solange *ein* Baustein oft genug vorkam: `pager` war seit Schritt 5
         * in der Liste und wurde nie gefunden, ohne dass es auffiel.
         *
         * > **Ein Eintrag in einer Liste, den der Ausdruck nie erreicht, sieht
         * > aus wie eine Abdeckung und ist eine Lücke.**
         */
        $this->assertGreaterThanOrEqual(
            3,
            count($gesehen),
            sprintf(
                "Es werden nur %d verschiedene Fugen gefunden: %s\n\n".
                'Dann rechnet dieser Wächter an fast nichts mehr nach — entweder ist die Suche kaputt '.
                'oder die Listen sind es.',
                count($gesehen),
                implode(', ', array_keys($gesehen)) ?: '(keine)',
            ),
        );
    }

    /**
     * Und jeder Baustein beider Listen kommt irgendwo wirklich vor.
     *
     * **Das ist der Wächter über den Wächter.** Ein Name in {@see self::ENDS_FLUSH}
     * oder {@see self::STARTS_FLUSH}, den keine Vorlage trägt, ist entweder ein
     * Tippfehler oder ein Baustein, den es nicht mehr gibt — und in beiden Fällen
     * eine Zeile, die nach Abdeckung aussieht und keine ist. Genau das war
     * `pager` fünf Schritte lang.
     */
    public function test_every_listed_block_really_exists(): void
    {
        $vorlagen = '';

        foreach ($this->templates() as $path) {
            $vorlagen .= (string) file_get_contents($path);
        }

        preg_match_all('/class="([^"]*)"/', $vorlagen, $treffer);

        $klassen = [];

        foreach ($treffer[1] as $liste) {
            foreach (preg_split('/\s+/', trim($liste)) ?: [] as $klasse) {
                $klassen[$klasse] = true;
            }
        }

        $stil = $this->stylesheet();

        foreach (self::HAS_OWN_AIR as $baustein) {
            $this->assertArrayHasKey(
                $baustein,
                $klassen,
                sprintf(
                    '`.%s` steht in HAS_OWN_AIR, aber in keiner Vorlage. '.
                    'Ein Baustein, den es nicht gibt, deckt nichts ab.',
                    $baustein,
                ),
            );

            $this->assertArrayHasKey(
                $baustein,
                $stil,
                sprintf('`.%s` steht in HAS_OWN_AIR, und app.css kennt die Klasse nicht.', $baustein),
            );

            /*
             * **Und die Behauptung selbst wird nachgerechnet.**
             *
             * Ein Eintrag hier heisst: „Dieser Baustein bringt seine Luft im
             * Padding mit, deshalb braucht er keinen Rand." Bekommt er später
             * doch einen Rand, ist die Ausnahme überflüssig — und sie nimmt
             * dann einen Baustein aus dem Blick, der gar keine mehr braucht.
             *
             * > **Eine Ausnahme, deren Begründung weggefallen ist, sieht aus
             * > wie eine Entscheidung und ist ein Rest.**
             */
            $this->assertFalse(
                $stil[$baustein]['top'] || $stil[$baustein]['bottom'],
                sprintf(
                    '`.%s` steht in HAS_OWN_AIR und hat in app.css einen Rand. Dann ist die '.
                    'Ausnahme überflüssig: Der Baustein bringt seinen Abstand ohnehin mit, und '.
                    'die Zeile nimmt ihn nur aus dem Blick dieses Wächters.',
                    $baustein,
                ),
            );

            $this->assertStringContainsString(
                'padding',
                $this->rule($baustein),
                sprintf(
                    '`.%s` steht in HAS_OWN_AIR, hat aber gar kein Padding. Die Ausnahme behauptet '.
                    'Luft, die es nicht gibt — und der Baustein klebt an seinem Nachbarn, ohne '.
                    'dass jemand es meldet.',
                    $baustein,
                ),
            );
        }
    }

    /**
     * Die Deklarationen einer Klasse, so wie `app.css` sie schreibt.
     *
     * Sie werden neben {@see self::stylesheet()} gebraucht, weil dort nur
     * Ränder und Richtung ankommen — für die Gegenprobe zu {@see
     * self::HAS_OWN_AIR} zählt aber, ob überhaupt ein Padding dasteht.
     */
    private function rule(string $klasse): string
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        return preg_match('/(^|\n)\.'.preg_quote($klasse, '/').'\s*\{([^{}]*)\}/s', $css, $treffer) === 1
            ? $treffer[2]
            : '';
    }
}
