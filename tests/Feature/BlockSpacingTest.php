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
     * Was eine Tabelle selbst anordnet.
     *
     * ## Drei Fälle einer Familie, und der dritte macht daraus eine Regel
     *
     * Zwei Zellen einer `.stacks`-Tabelle sind keine zwei Blöcke im Fluss: Bei
     * 390 px stapeln sie, und ihren Abstand hat `.stacks td` — eine
     * Nachfahrenregel, die {@see self::stylesheet()} bewusst nicht liest, weil
     * sie eine Lage beschreibt und keinen Baustein.
     *
     * Sichtbar werden diese Scheinfugen durch {@see self::transparent()}: Ein
     * `<td>` ohne Klasse reicht die Kante seines Kindes durch, und dann steht
     * `.badge` aus der einen Zelle unmittelbar über `.button-row` aus der
     * nächsten. Für einen klassenlosen `<div>` ist das richtig; für eine Zelle
     * nicht, denn die Zelle ist kein durchsichtiger Behälter — sie ist der Ort,
     * an dem die Tabelle den Abstand macht.
     *
     * `cell-name + ident` stand dafür seit dem 14. August in
     * {@see self::OPEN_SEAMS}, mit einer Begründung, die genau das sagte. Am
     * 17. August kamen `cell-name + cell-name` und `badge + button-row` dazu —
     * und damit ist die Grenze erreicht, die im Kopf dieser Klasse steht:
     *
     * > **Eine Liste von Nachbarn, die wächst, ist keine Regel — sie ist eine
     * > Aufzählung der Fälle, die schon jemand gesehen hat.**
     *
     * @var list<string>
     */
    private const TABLE_PARTS = ['td', 'th', 'tr', 'thead', 'tbody', 'tfoot', 'caption', 'colgroup'];

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
     * **Und am selben Abend eine siebte**, aus demselben Grund: Seit ein
     * klassenloser Behälter seine Kante durchreicht ({@see self::transparent()}),
     * werden **neun** Fugen sichtbar, die vorher zwischen den Rastern lagen. Keine
     * einzige ist dabei weggefallen — die Liste wächst von 31 auf 40, weil der
     * Wächter mehr sieht und nicht, weil die Gestaltung schlechter wurde.
     *
     * Ob sie zu eng stehen, entscheidet ein Blick und keine Regel. Sie gehören
     * damit hierher und in die Bilderrunde aus Schritt 12 — nicht in `app.css`.
     *
     * > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
     * > kleiner werden kann.**
     *
     * ## Am 17. August ist sie es geworden: von 42 auf 35
     *
     * Und wieder ohne eine einzige Änderung an der Gestaltung. Drei Korrekturen
     * an diesem Wächter, jede mit ihrer eigenen Begründung an Ort und Stelle:
     *
     * 1. **Die Tiefenzählung** hielt `<Link>` für ein leeres HTML-Element
     *    ({@see self::pairs()}). Sie kippte um eins, und alles dahinter bekam den
     *    falschen Elternteil — `sections + scrolls` und ein Teil von
     *    `sections + notice` waren Scheinnachbarn.
     * 2. **Die Kanten** wurden je Klasse gefragt statt je Element, und die Regeln
     *    der Vorlage selbst wurden gar nicht gelesen ({@see self::flushClasses()},
     *    {@see self::stylesheet()}). `output + button-row`,
     *    `sections + button-row` und `with-unit + dependent` waren seit jeher
     *    geschlossen — von `.footer-row`, `.form-top`, `.postscript` und `.hint`.
     * 3. **Die Tabelle** ordnet ihre Zellen selbst an ({@see self::TABLE_PARTS}).
     *    `cell-name + ident` und `check + cell-name` waren nie Fugen.
     *
     * Fünf der sieben waren also nie offen, und zwei waren es nie gewesen. Keine
     * einzige ist durch eine neue Regel in `app.css` geschlossen worden.
     *
     * > **Eine Zahl, die kleiner wird, weil der Zähler richtig zählt, ist keine
     * > Verbesserung — sie ist die Berichtigung einer Behauptung.**
     *
     * @var list<string>
     */
    private const OPEN_SEAMS = [
        'arrow + label',
        'button + button',
        'button-row + button',
        'button-row + form',
        /*
         * **Eine Brotkrume ist kein Block.** `<Link class="link">Kunden</Link> ·
         * <span class="ident">…</span>` steht in `Customers/Show.vue` und
         * `Operations/Show.vue` in einer Zeile, getrennt von einem Mittelpunkt;
         * zwei Blöcke im Fluss sind das nicht, und ein Abstand dazwischen wäre
         * an dieser Stelle falsch.
         *
         * **Gesehen wurde sie erst am 17. August**, und zwar durch die Korrektur
         * eine Ebene tiefer: Solange `<Link>` als leeres HTML-Element galt, kippte
         * die Tiefenzählung, und dieser Nachbar entstand in der Zählung gar nicht.
         *
         * > **Ein Wächter mit einem Zählfehler meldet nicht zu viel, sondern das
         * > Falsche — und schweigt über das Richtige.**
         */
        'link + ident',
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
        'ident + button-row',
        'ident + ident',
        'ident + notice',
        /*
         * ## Sechs Fugen, die dieser Wächter am 19. August zum ersten Mal sah
         *
         * `.quiet` gab es in `app.css` bis dahin nur als `td.quiet` und
         * `td .quiet` — ausserhalb einer Zelle war die Klasse wirkungslos
         * (`docs/64`, Befund 11). Seit sie eine freistehende Regel hat, ist sie
         * für diesen Wächter ein Baustein, und **die Nachbarschaften waren
         * schon vorher da.** Drei davon sind echte Fugen und in `app.css`
         * gedeckt (`.button-row + .quiet`, `.quiet + .notice`,
         * `.quiet + .scrolls`); die hier stehenden sind es nicht, und alle aus
         * demselben Grund:
         *
         * **`.quiet` ist meistens gar kein Block.** Es ist ein `<span>` in
         * einer Zelle, neben einer Marke, hinter einem Verweis, im
         * Ausgabekasten, neben einem Feld — dort ordnet die Zelle an oder der
         * Text fliesst, und eine Fuge gibt es nicht.
         *
         * > **Ein Baustein, den man auch inline benutzt, erzeugt Fugen, die
         * > keine sind.**
         *
         * `quiet + quiet` steht in vier Vorlagen und jedes Mal in einer Zelle,
         * wo `td .quiet` mit `display: block` und `margin-top: 3px` den Abstand
         * macht — dieselbe Sorte Scheinfuge wie `cell-name + ident` darüber.
         */
        'output + quiet',
        'badge + quiet',
        'folds + quiet',
        'quiet + ident',
        'link + quiet',
        'field + quiet',
        'quiet + quiet',
        /*
         * **Zwei Fugen, die nie zu diesem Lauf gehörten.** Sie standen schon
         * vorher hier; die Umstellung hat sie kurz gedeckt und dann wieder
         * freigegeben, weil die Regel darüber auf die drei gemessenen Fälle
         * verengt wurde.
         */
        'scrolls + scrolls',
        'button-row + scrolls',
        'leaf + leaf',
        'link + link',
        'pager-state + button',
        'sections + form',
        'toggle + button-row',
        'toggle + choices',
        'toggle + ident',
        'toggle + dependent',
        'toggle + with-unit',
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
     * ## Und was die Vorlage selbst dazu sagt
     *
     * **Ein `<style scoped>` in einer Seite ist kein Sonderfall, sondern der
     * Normalfall für einmalige Abstände.** Neunzehn Vorlagen haben einen, und
     * fünf davon setzen genau das, wonach dieser Wächter fragt: `.form-top`,
     * `.footer-row`, `.spaced`, `.postscript`, `.after-tiles` — alle mit
     * `margin-top: var(--block-gap)` oder `var(--gap)`, alle einmalig, alle
     * unsichtbar für einen Wächter, der nur `app.css` liest.
     *
     * Bis zum 17. August war er genau das, und damit hielt er jeden dieser
     * Bausteine für bündig. Gefunden hat es P6 Schritt 9, nachdem die
     * Tiefenzählung berichtigt war: `Domains/Show.vue` setzt zwei `.sections`
     * untereinander, und zwischen ihnen steht seit jeher `.form-top`.
     *
     * > **Ein Wächter, der eine Regel nicht liest, meldet nicht „ungeprüft" — er
     * > meldet „verletzt".**
     *
     * Die Regeln der Vorlage kommen deshalb **hinter** `app.css` in denselben
     * Durchlauf. Das ist die Kaskade und keine Ersetzung: Ein `scoped`-Block, der
     * nur `padding` setzt, lässt die Ränder aus `app.css` stehen. Und er gilt nur
     * für diese eine Vorlage — deshalb wird er hier durchgereicht und nicht
     * gesammelt.
     *
     * @param  string  $scoped  Die `<style>`-Blöcke der Vorlage, oder leer.
     * @return array<string, array{top: bool, bottom: bool, row: bool|null, gap: bool}>
     */
    private function stylesheet(string $scoped = ''): array
    {
        $ohneKommentare = static fn (string $css): string => (string) preg_replace('#/\*.*?\*/#su', '', $css);

        $css = $ohneKommentare((string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'))
            ."\n".$ohneKommentare($scoped);

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
     * Die Klassen, unter denen ein Element an dieser Kante bündig ist — oder keine.
     *
     * ## Die Frage gilt dem Element und nicht der Klasse
     *
     * **Der zweite Fehler, den P6 Schritt 9 an diesem Wächter gefunden hat.**
     * Vorher standen hier zwei Listen von Klassennamen, und jedes Element wurde
     * gegen beide gehalten: Trug es `class="sections form-top"`, so war
     * `sections` bündig, und die Fuge wurde gemeldet — obwohl `form-top`
     * daneben einen Rand mitbringt und das Element damit gar nicht bündig ist.
     *
     * > **Zwei Klassen an einem Element sind keine zwei Elemente.** Im Browser
     * > gewinnt die eine, die einen Rand setzt; in einer Liste von Klassennamen
     * > kommt diese Information nicht vor.
     *
     * Deshalb wird hier zuerst gefragt, ob **irgendeine** Klasse des Elements
     * einen Rand an dieser Kante setzt. Erst wenn keine das tut, ist das Element
     * bündig — und dann werden die Klassen zurückgegeben, unter denen es in der
     * Meldung erscheinen soll.
     *
     * ## Was ein Baustein ist, steht in `app.css` — und nur dort
     *
     * Die Regeln der Vorlage entscheiden mit, **ob** ein Element bündig ist; sie
     * entscheiden nicht, **was** ein Baustein ist. Eine Klasse, die es nur in
     * einem `scoped`-Block gibt, ist ein einmaliger Griff und kein Baustein des
     * Gestaltungssystems — `.sr` in `PasswordFields.vue` etwa ist eine Spanne
     * für Vorleseprogramme, die überhaupt keinen Kasten zeichnet.
     *
     * Beim ersten Wurf dieser Korrektur zählten die `scoped`-Klassen mit, und
     * prompt stand `check + sr` als Fuge da — zwei Zeichen nebeneinander in
     * einem Listeneintrag.
     *
     * > **Eine Regel, die eine Kante verschiebt, macht aus ihrem Element noch
     * > keinen Baustein.**
     *
     * @param  array<string, array{top: bool, bottom: bool, row: bool|null, gap: bool}>  $stil
     * @param  list<string>  $bausteine  Die Klassennamen aus `app.css`.
     * @param  'top'|'bottom'  $kante
     * @return list<string>
     */
    private function flushClasses(string $attributes, array $stil, array $bausteine, string $kante): array
    {
        if (preg_match('/class="([^"]*)"/', $attributes, $treffer) !== 1) {
            return [];
        }

        $namen = [];

        foreach (preg_split('/\s+/', trim($treffer[1])) ?: [] as $klasse) {
            // Ein Baustein, dessen Luft im Padding steckt, ist nicht bündig —
            // auch wenn `app.css` an ihm keinen Rand stehen hat.
            if (in_array($klasse, self::HAS_OWN_AIR, true)) {
                return [];
            }

            if (isset($stil[$klasse]) && $stil[$klasse][$kante]) {
                return [];
            }

            if (in_array($klasse, $bausteine, true)) {
                $namen[] = $klasse;
            }
        }

        return $namen;
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
     * Die `<style>`-Blöcke einer Vorlage.
     *
     * Gelesen wird auch ein Block **ohne** `scoped`: Er gilt dann global, und
     * damit erst recht für die Vorlage, in der er steht.
     */
    private function scoped(string $source): string
    {
        preg_match_all('#<style[^>]*>(.*?)</style>#su', $source, $treffer);

        return implode("\n", $treffer[1]);
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

                return $benannt ? '<div data-slot>' : '';
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

            if (! in_array($folgend[2], $void, true) && ! str_ends_with(rtrim($folgend[3]), '/')) {
                $tiefe++;
            }
        }

        // `$i` steht hinter dem schliessenden Tag. Ein `</div>` dort heisst:
        // Der Vorgänger war das letzte Kind, und dann gibt es kein Geschwister.
        return isset($tags[$i]) && $tags[$i][1] !== '/' ? $i : null;
    }

    /**
     * Das erste und das letzte Kind eines Tags.
     *
     * **Für die Durchsichtigkeit klassenloser Behälter** — siehe
     * {@see self::transparent()}.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $tags
     * @param  list<string>  $void
     * @return array{0: ?int, 1: ?int}
     */
    private function children(array $tags, int $index, array $void): array
    {
        $tiefe = 1;
        $erstes = null;
        $letztes = null;

        for ($i = $index + 1; $i < count($tags) && $tiefe > 0; $i++) {
            $tag = $tags[$i];

            if ($tag[1] === '/') {
                $tiefe--;

                continue;
            }

            if ($tiefe === 1) {
                $erstes ??= $i;
                $letztes = $i;
            }

            if (! in_array($tag[2], $void, true) && ! str_ends_with(rtrim($tag[3]), '/')) {
                $tiefe++;
            }
        }

        return [$erstes, $letztes];
    }

    /**
     * Ein klassenloser Behälter ist durchsichtig.
     *
     * ## Der siebte Fall derselben Fuge, und der erste unsichtbare
     *
     * Der Betreiber hat ihn am 15. August 2026 im Prüflauf gemeldet
     * (`docs/55`, Befund 10): Zwischen „Speichern"/„Abbrechen" des
     * Rechte-Editors und der Liste darunter war nichts.
     *
     * **Dieser Wächter konnte das nicht sehen, und zwar aus zwei Gründen
     * gleichzeitig.** Das Formular des Rechte-Editors trug **keine Klasse** —
     * damit passte es in keine der beiden Listen. Und sein letztes Kind, die
     * Knopfreihe, hat **kein Geschwister**, weil es das letzte ist. Die Fuge fiel
     * genau zwischen beide Raster.
     *
     * > **Ein Baustein ohne Klasse steht in keiner Liste — auch nicht in der der
     * > Fehler.**
     *
     * Ein Element ohne Klasse bringt in diesem Panel nichts mit: kein Rand, kein
     * Rahmen, keine Fläche. Es endet dort, wo sein letztes Kind endet, und fängt
     * dort an, wo sein erstes anfängt. Genau so wird es hier behandelt.
     *
     * Der Behälter wird dabei **nicht** übersprungen wie ein `v-if`: Er ist da,
     * seine Kinder sind es auch. Was durchgereicht wird, ist seine **Kante**.
     *
     * ## Ein Behälter bleibt undurchsichtig, und das war beim ersten Wurf falsch
     *
     * Der **benannte Platz** (`<template #actions>`) wird von {@see
     * self::rendered()} zu einem Kasten gemacht, damit seine Kinder unter sich
     * bleiben — das Layout setzt sie ganz woandershin, in die Kopfzeile der
     * Seite. Er trägt keine Klasse, und die Durchsichtigkeit hat ihn beim ersten
     * Wurf prompt aufgemacht: Der letzte Knopf aus `#actions` wurde wieder zum
     * Nachbarn dessen, was im Quelltext darunter steht — drei Scheinnachbarn,
     * dieselben drei, die der Umbau vom 15. August schon einmal beseitigt hatte.
     *
     * > **Zwei Dinge, die im Quelltext gleich heissen, sind im Browser nicht
     * > dasselbe.** Der Satz stand im Kopf dieses Wächters, und ich habe ihn
     * > eine Methode weiter unten gebrochen.
     *
     * Deshalb die Marke `data-slot`: Sie unterscheidet den Kasten, der etwas
     * **verschiebt**, von dem, der nur etwas **umschliesst**.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $tags
     * @param  list<string>  $void
     * @return array{0: string, 1: string} Attribute für die untere und die obere Kante
     */
    private function transparent(array $tags, int $index, array $void): array
    {
        $tag = $tags[$index];

        if (str_contains($tag[3], 'class="') || str_contains($tag[3], 'data-slot')) {
            return [$tag[3], $tag[3]];
        }

        [$erstes, $letztes] = $this->children($tags, $index, $void);

        return [
            $letztes !== null ? $tags[$letztes][3] : $tag[3],
            $erstes !== null ? $tags[$erstes][3] : $tag[3],
        ];
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
     * @param  string  $scoped  Die `<style>`-Blöcke der Vorlage — siehe {@see self::stylesheet()}.
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(string $template, string $scoped = ''): array
    {
        /*
         * **Die leeren HTML-Elemente — und `link` ist hier eine Falle.**
         *
         * Verglichen wird **ohne** `strtolower()`, und das ist der Unterschied
         * zwischen `<link>` und Inertias `<Link>`. Bis zum 17. August stand hier
         * ein `strtolower()`, und damit galt jede `<Link>`-Komponente als leeres
         * Element: Das öffnende Tag erhöhte die Tiefe nicht, das schliessende
         * `</Link>` senkte sie — die Zählung kippte um eins, und alles dahinter
         * bekam den falschen Elternteil.
         *
         * Gefunden hat es P6 Schritt 9: Der Wächter meldete für zwei Seiten
         * `.section` als Geschwister von `.sections`, obwohl beide sauber
         * verschachtelt waren. Sichtbar wurde es erst, als die Tiefenzählung
         * Schritt für Schritt mitgeschrieben wurde — `<Link>` ging von 3 auf 3.
         *
         * > **Zwei Dinge, die im Quelltext gleich heissen, sind im Browser nicht
         * > dasselbe** — und `strtolower()` macht aus dem einen das andere.
         *
         * HTML-Elemente stehen in diesen Vorlagen klein, Komponenten gross; ein
         * genauer Vergleich trennt sie ohne eine zweite Liste.
         */
        $void = ['input', 'br', 'hr', 'img', 'meta', 'link', 'source', 'area', 'col'];

        preg_match_all('/<(\/?)([a-zA-Z][\w.-]*)([^>]*)>/s', $template, $tags, PREG_SET_ORDER);

        $stil = $this->stylesheet($scoped);
        $bausteine = array_keys($this->stylesheet());

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

            if (! in_array($tag[2], $void, true) && ! str_ends_with(rtrim($tag[3]), '/')) {
                $stapel[] = $tag[3];
            }
        }

        $paare = [];

        foreach ($tags as $start => $tag) {
            if ($tag[1] === '/' || in_array($tag[2], $void, true) || str_ends_with(rtrim($tag[3]), '/')) {
                continue;
            }

            // Zwei Zellen nebeneinander ordnet die Tabelle — siehe self::TABLE_PARTS.
            if (in_array($tag[2], self::TABLE_PARTS, true)) {
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
                $nachbarn[] = $naechster;

                if (preg_match('/\sv-(?:if|else-if|else)\b/', $tags[$naechster][3]) !== 1) {
                    break;
                }

                $naechster = $this->sibling($tags, $naechster, $void);
            }

            [$untenKante] = $this->transparent($tags, $start, $void);
            $untenBuendig = $this->flushClasses($untenKante, $stil, $bausteine, 'bottom');

            if ($untenBuendig === []) {
                continue;
            }

            foreach ($nachbarn as $nachbarIndex) {
                [, $obenKante] = $this->transparent($tags, $nachbarIndex, $void);

                foreach ($untenBuendig as $unten) {
                    foreach ($this->flushClasses($obenKante, $stil, $bausteine, 'top') as $oben) {
                        $paare[] = [$unten, $oben];
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

    /**
     * Der Absatz ohne Klasse lässt unter sich Luft.
     *
     * **Diesen einen Baustein sieht der Wächter darunter nicht.** Er kennt
     * Bausteine an ihrer Klasse, und ein Absatz ohne Klasse hat keine — die
     * Fuge auf der Cronseite („Cronjobs laufen als p1139 …" über der Meldung
     * zur Zeitzone, `docs/64` Befund 4) ist für ihn unsichtbar.
     *
     * > **Ein Wächter, der Bausteine an ihrer Klasse kennt, sieht den Baustein
     * > ohne Klasse nicht.**
     *
     * Deshalb hier eine eigene Zusage: Die Regel gibt es, und sie setzt einen
     * Wert. Das ist weniger, als der Wächter darunter kann — aber es ist mehr
     * als nichts, und es ist genau das, was ohne sie stillschweigend
     * verschwände.
     */
    public function test_a_paragraph_without_a_class_leaves_air_below(): void
    {
        $css = (string) preg_replace(
            '#/\*.*?\*/#su',
            '',
            (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'),
        );

        $this->assertSame(
            1,
            preg_match('/p:not\(\[class\]\)\s*\{([^{}]*)\}/s', $css, $regel),
            'In app.css gibt es keine Regel für `p:not([class])` mehr. Ein Absatz ohne Klasse hat '.
            'durch Tailwinds Reset gar keinen Rand — was unter ihm steht, klebt an ihm.',
        );

        /*
         * **Der Wert wird geholt und dann angesehen, nicht in den Ausdruck
         * geschrieben.** Der erste Anlauf lautete
         * `margin-bottom\s*:\s*(?!0[;\s}])[^;]+` — und er war grün, als der
         * Rand auf `0` stand: `\s*` tritt zurück, bis die Vorausschau auf das
         * Leerzeichen statt auf die Null zeigt, und dann trifft sie.
         *
         * > **Eine Vorausschau hinter `\s*` prüft nicht, was dort steht,
         * > sondern was daneben passt.**
         */
        $this->assertSame(
            1,
            preg_match('/margin-bottom\s*:([^;]+)/', $regel[1], $wert),
            'Die Regel für `p:not([class])` nennt keinen Rand nach unten.',
        );

        $this->assertNotContains(
            trim($wert[1]),
            ['0', '0px'],
            "Die Regel für `p:not([class])` setzt den Rand nach unten auf null.\n\n".
            'Auf der Cronseite steht „Cronjobs laufen als p1139 …" als schlichtes `<p>`, und die '.
            'Meldung über die Zeitzone klebte daran (docs/64, Befund 4).',
        );
    }

    public function test_the_rule_still_lists_what_it_used_to(): void
    {
        $abgedeckt = $this->covered();

        foreach ([['scrolls', 'button-row'], ['field', 'button-row'], ['button-row', 'sections'], ['button-row', 'split']] as $muss) {
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
            $quelle = (string) file_get_contents($path);

            foreach ($this->pairs($this->rendered($quelle), $this->scoped($quelle)) as $paar) {
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
