<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * In einer Meldung steht der Satz in **einem** Element — nicht als Text neben
 * einem.
 *
 * ## Der Anlass, und er hat keine Zahl erzeugt
 *
 * Gefunden am 22. August 2026 beim Vormessen der Bilderrunde (`docs/75`), im
 * Aufsatz dieses Containers. Die Meldung „nicht gefragt" auf der Domainseite
 * sah bei 390 px so aus:
 *
 *     F   p6-      hat die Prüfung gar nicht
 *     ü   abnah    stattgefunden. Das liegt an diesem
 *     r   me.in    Server und nicht an der Zone — über
 *         valid    ihre Einträge ist damit nichts gesagt.
 *
 * `.notice` ist eine **Flexbox** (`app.css`). Der Wortlaut stand als drei
 * Geschwister darin — der Textknoten „Für", das `span.ident` mit dem Namen und
 * der Rest des Satzes —, und daraus werden drei Flexkinder mit je eigener
 * Spalte. „Für" bekam fünf Pixel Breite und brach in drei Zeilen.
 *
 * **Der Überlauf war dabei `0`, in allen vier Lagen, und die Gegenprobe schlug
 * mit `200/200` aus.** Die Messung war fehlerfrei und hat über diese Ansicht
 * nichts gesagt.
 *
 * > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
 * > Betrachter.**
 *
 * ## Warum die Regel schon galt, bevor sie jemand aufschrieb
 *
 * Beim Bau dieses Wächters standen **62** Meldungen im Bestand, und
 * einundsechzig davon halten die Regel bereits — die meisten mit genau der
 * Form, die daneben in derselben Datei steht:
 *
 *     <p class="notice warn">
 *       <span><span class="ident">{{ caa.name }}</span> — {{ caa.reason }}</span>
 *     </p>
 *
 * Die eine Ausnahme war die aus P7. Das ist der Fall, für den es hier Wächter
 * gibt: eine Ordnung, die überall eingehalten wird, ohne dass etwas sie hält.
 *
 * > **Eine Gewohnheit, an die sich einundsechzig Stellen halten, ist trotzdem
 * > keine Regel, solange die zweiundsechzigste sie brechen darf.**
 *
 * ## Was er nicht prüft
 *
 * Ob der Satz **gut** ist, und ob eine Meldung überhaupt an diese Stelle
 * gehört. Und er prüft nur `.notice` — andere Flexboxen dieses Projekts haben
 * dasselbe Problem und je einen eigenen Grund, warum es dort nicht auftritt.
 * Wer eine neue baut, hat damit keinen Wächter.
 */
final class NoticeChildrenTest extends TestCase
{
    /**
     * Marken ohne Inhalt — sie haben kein Schlusstag und dürfen die Tiefe
     * nicht verstellen.
     *
     * @var list<string>
     */
    private const VOID = ['br', 'img', 'input', 'hr', 'meta', 'link', 'source', 'wbr'];

    /**
     * Jede Meldung, in der ein Textknoten neben einem Element steht.
     *
     * **Der Zerleger achtet auf Anführungszeichen**, und das ist kein
     * Feinschliff: Genau die Zeile, um die es geht, trägt ein `>` im Attribut
     * (`v-if="… .length > 0"`). Ein Ausdruck mit `[^>]*` hört dort auf und
     * findet sie nie.
     *
     * > **Ein Ausdruck, der ein Tag bis zum nächsten `>` liest, liest ein
     * > halbes Tag, sobald eines im Attribut steht.**
     *
     * @return list<array{string, int, string}>
     */
    private function mixed(): array
    {
        $funde = [];

        foreach ($this->templates() as $pfad => $quelle) {
            foreach ($this->notices($quelle) as [$zeile, $kinder, $text]) {
                if ($kinder > 0 && trim($text) !== '') {
                    $funde[] = [$pfad, $zeile, $this->condensed($text)];
                }
            }
        }

        return $funde;
    }

    /**
     * Alle `.vue`-Dateien, Kommentare durch Leerzeichen ersetzt.
     *
     * **Ersetzt und nicht entfernt** — sonst verschiebt sich jede Zeilennummer
     * danach, und der Wächter zeigt auf die falsche Stelle.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'vue') {
                continue;
            }

            $quelle = (string) file_get_contents($datei->getPathname());

            $dateien[str_replace($wurzel.'/', '', $datei->getPathname())] = (string) preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $t): string => str_repeat(' ', strlen($t[0])),
                $quelle,
            );
        }

        ksort($dateien);

        return $dateien;
    }

    /**
     * Je Meldung in dieser Quelle: Zeile, Zahl der Kindelemente, Text daneben.
     *
     * @return list<array{int, int, string}>
     */
    private function notices(string $quelle): array
    {
        $marken = $this->tags($quelle);
        $gefunden = [];

        foreach ($marken as $i => [$anfang, $ende, $tag]) {
            if (preg_match('/^<(\w[\w-]*)/', $tag, $t) !== 1) {
                continue;
            }

            $marke = $t[1];

            if (preg_match('/class="[^"]*\bnotice\b/', $tag) !== 1) {
                continue;
            }

            if (str_ends_with($tag, '/>') || in_array($marke, self::VOID, true)) {
                continue;
            }

            $tiefe = 0;
            $kinder = 0;
            $text = '';
            $vor = $ende + 1;

            foreach (array_slice($marken, $i + 1) as [$a, $e, $inner]) {
                if ($tiefe === 0) {
                    $text .= substr($quelle, $vor, $a - $vor);
                }

                preg_match('/^<\/?(\w[\w-]*)/', $inner, $n);
                $name = $n[1] ?? '';

                if (str_starts_with($inner, '</')) {
                    if ($tiefe === 0 && $name === $marke) {
                        break;
                    }

                    $tiefe--;
                } elseif (! str_ends_with($inner, '/>') && ! in_array($name, self::VOID, true)) {
                    if ($tiefe === 0) {
                        $kinder++;
                    }

                    $tiefe++;
                } elseif ($tiefe === 0) {
                    $kinder++;
                }

                $vor = $e + 1;
            }

            $gefunden[] = [substr_count(substr($quelle, 0, $anfang), "\n") + 1, $kinder, $text];
        }

        return $gefunden;
    }

    /**
     * Die Marken einer Quelle als `[Anfang, Ende, Text]`.
     *
     * @return list<array{int, int, string}>
     */
    private function tags(string $quelle): array
    {
        $marken = [];
        $i = 0;
        $laenge = strlen($quelle);

        while ($i < $laenge) {
            if ($quelle[$i] !== '<') {
                $i++;

                continue;
            }

            $j = $i + 1;
            $quote = null;

            while ($j < $laenge) {
                $c = $quelle[$j];

                if ($quote !== null) {
                    if ($c === $quote) {
                        $quote = null;
                    }
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>') {
                    break;
                }

                $j++;
            }

            $marken[] = [$i, $j, substr($quelle, $i, $j - $i + 1)];
            $i = $j + 1;
        }

        return $marken;
    }

    private function condensed(string $text): string
    {
        return substr((string) preg_replace('/\s+/', ' ', trim($text)), 0, 60);
    }

    /**
     * **Die Gegenprobe, und sie kommt zuerst.**
     *
     * Findet der Zerleger keine Meldungen, prüft der Fall darunter null Stellen
     * und ist grün, ohne etwas gesehen zu haben. Zweiundsechzig sind es beim
     * Bau dieses Wächters.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_there_are_notices_to_check(): void
    {
        $anzahl = 0;

        foreach ($this->templates() as $quelle) {
            $anzahl += count($this->notices($quelle));
        }

        $this->assertGreaterThanOrEqual(
            30,
            $anzahl,
            'Der Zerleger findet fast keine Meldungen — dann sagt der Fall darunter nichts.',
        );
    }

    /**
     * **Und die Gegenprobe zur anderen Hälfte: Der Zerleger sieht die Kinder.**
     *
     * Der Fall darüber zählt Meldungen. Er sagt nichts darüber, ob innerhalb
     * einer davon überhaupt etwas erkannt wird — ein Zerleger, der jedes
     * Kindelement verschluckt, meldete `kinder = 0` für alle und wäre auf ewig
     * grün.
     */
    public function test_the_scan_sees_children_inside_a_notice(): void
    {
        $mit = 0;

        foreach ($this->templates() as $quelle) {
            foreach ($this->notices($quelle) as [, $kinder]) {
                if ($kinder > 0) {
                    $mit++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            20,
            $mit,
            'Der Zerleger findet in keiner Meldung ein Kindelement — dann misst er nichts.',
        );
    }

    /**
     * **Und die Gegenprobe, deren Fehlen einen Bruch stumm gemacht hat.**
     *
     * Am 22. August 2026 belegt: Wird die Beachtung der Anführungszeichen im
     * Zerleger abgeschaltet, bleibt dieser Wächter **grün**. Er verliert dabei
     * genau sechs der zweiundsechzig Meldungen — jene, deren öffnendes Tag ein
     * `>` im Attribut trägt (`v-if="… .length > 0"`) —, und die beiden
     * Gegenproben darüber zählen einfach sechs weniger.
     *
     * > **Eine Gegenprobe über eine Menge merkt nicht, dass ein Teil der Menge
     * > fehlt.**
     *
     * Der bittere Teil: Die Meldung, um die es beim Bau dieses Wächters ging,
     * ist eine von diesen sechs. Ein Zerleger mit diesem Fehler hätte den
     * Anlass selbst nicht gefunden.
     *
     * Deshalb ein Prüfkörper aus der Hand statt einer Zahl über den Bestand.
     * Er hängt an keiner Datei und wird von keinem Aufräumen kleiner.
     */
    public function test_the_scan_reads_a_tag_with_an_angle_bracket_in_an_attribute(): void
    {
        $probe = '<template><p v-if="a.length > 0" class="notice warn">Text<span>x</span></p></template>';

        $gefunden = $this->notices($probe);

        $this->assertCount(
            1,
            $gefunden,
            'Der Zerleger findet eine Meldung nicht, deren Tag ein > im Attribut traegt. '
            .'Sechs Meldungen dieses Projekts sehen so aus — sie waeren unsichtbar.',
        );

        $this->assertSame(
            1,
            $gefunden[0][1],
            'Der Zerleger liest das Tag, sieht aber sein Kindelement nicht.',
        );

        $this->assertStringContainsString(
            'Text',
            $gefunden[0][2],
            'Der Zerleger liest das Tag, sieht aber den Textknoten daneben nicht.',
        );
    }

    /**
     * Keine Meldung stellt Text neben ein Element.
     */
    public function test_a_notice_does_not_put_text_beside_an_element(): void
    {
        $funde = array_map(
            static fn (array $f): string => sprintf('%s:%d — "%s…"', $f[0], $f[1], $f[2]),
            $this->mixed(),
        );

        $this->assertSame([], $funde, implode("\n", [
            'In diesen Meldungen steht ein Textknoten neben einem Element:',
            ...$funde,
            '',
            '.notice ist eine Flexbox. Text und Element nebeneinander ergeben zwei',
            'Flexkinder, und jedes bekommt seine eigene Spalte — bei 390 px steht',
            'dann ein Wort mit fuenf Pixeln Breite neben dem Rest des Satzes,',
            'Zeichen fuer Zeichen umgebrochen.',
            '',
            'Der Ueberlauf ist dabei 0. Die Messung der Bilderrunde findet das',
            'nicht; gefunden hat es ein Betrachter.',
            '',
            'Der Weg: den ganzen Satz in ein <span> legen, so wie es die uebrigen',
            'Meldungen dieses Projekts tun.',
        ]));
    }
}
