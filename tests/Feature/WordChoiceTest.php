<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Die Wortwahl der Oberfläche — mechanisch geprüft, wie docs/19 §5 es verlangt.
 *
 * **Warum dieser Test hier neu steht.** Es gab ihn schon einmal. Er hieß
 * `internal/ui/wortwahl_test.go` und ist beim Repo-Übergang zusammen mit dem
 * Go-Code verschwunden — die Vorgabe blieb, ihre Durchsetzung nicht. Neun
 * Monate später stand im Aufgabenkatalog „Fragt den Agenten nach seiner
 * Fassung", und aufgefallen ist es keinem Lauf, sondern dem ersten Menschen,
 * der auf die Vorgangsseite gesehen hat.
 *
 * Das ist dasselbe Muster wie beim Glob auf `./Seiten/`, bei den Unit-Namen
 * und bei `${VERSION}` im Pfad: Eine Regel, die auf etwas zeigt, und nichts,
 * das die Zeigerichtung prüft. Ein Dokument allein ist so eine Regel.
 *
 * **Was geprüft wird und was nicht.** docs/19 §6 nimmt Kommentare aus: Sie
 * dürfen die alten Wörter nennen, sie tragen die Geschichte der Entscheidung.
 * Deshalb liest der PHP-Teil nicht die Datei, sondern ihre Token — nur echte
 * Zeichenkettenliterale. Das ist die Entsprechung zum `go/ast` des Vorgängers.
 *
 * Im Vue-Teil wird der `<template>`-Block gelesen, ohne HTML-Kommentare. Was in
 * `<script>` als Zeichenkette steht, bleibt außen vor — dort steht in diesem
 * Projekt kein Anzeigetext, sondern er kommt vom Server und läuft damit schon
 * durch den PHP-Teil. Sollte sich das ändern, ist diese Zeile die Stelle, an
 * der es nachzuziehen ist.
 */
final class WordChoiceTest extends TestCase
{
    /**
     * Die Tabelle aus docs/19 §3, als Muster.
     *
     * Der Blick zurück (`(?<!…)`) ist kein Zierrat: Ohne ihn meldete „Fläche"
     * jedes „Oberfläche" — und ein Test, der bei jedem Lauf falsch schlägt,
     * wird abgeschaltet, nicht befolgt.
     *
     * @return array<string, array{0: string, 1: string}> Wort => [Muster, Ersatz]
     */
    public static function words(): array
    {
        $entries = [
            'einspielen' => 'installieren',
            'eingespielt' => 'installiert',
            'Fassung' => 'Version',
            'Rückweg' => 'Rollback, zurücksetzen',
            'Fläche' => 'Bereich',
            'Handgriff' => 'Aktion',
            'Anmeldeschale' => 'Login-Shell',
            'Krumen' => 'Pfadleiste',
            'Baucache' => 'Build-Cache',
            'Wirtspfad' => 'Host-Pfad',
            'Wirtsdateisystem' => 'Dateisystem des Hosts',
            'Gegenstelle' => 'Upstream-Adresse',
            'Spitzenreiter' => 'eine Angabe, wonach sortiert wird',
            'wegräumen' => 'entfernen',
            'geglückt' => 'erfolgreich',
            'Platte' => 'Datenträger',
        ];

        $cases = [];

        foreach ($entries as $word => $instead) {
            $cases[$word] = ['/(?<![A-Za-zÄÖÜäöüß])'.preg_quote($word, '/').'/iu', $instead];
        }

        return $cases;
    }

    #[DataProvider('words')]
    public function test_no_displayed_php_string_uses_a_spent_word(string $pattern, string $instead): void
    {
        $found = [];

        foreach ($this->phpFiles() as $path) {
            foreach ($this->stringLiterals($path) as $line => $literal) {
                if (preg_match($pattern, $literal) === 1) {
                    $found[] = sprintf('%s:%d  %s', $this->relative($path), $line, mb_substr($literal, 0, 90));
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "docs/19 §3: dieses Wort ist verbraucht, es heißt %s.\n\n  %s\n\n".
            'Steht es in einem Kommentar, ist der Test falsch — dann liest er nicht nur Literale. '.
            'Steht es in einem Text, den jemand liest, gehört es ersetzt.',
            $instead,
            implode("\n  ", $found),
        ));
    }

    #[DataProvider('words')]
    public function test_no_vue_template_uses_a_spent_word(string $pattern, string $instead): void
    {
        $found = [];
        $files = $this->files(dirname(__DIR__, 2).'/resources/js', 'vue');

        // Hier stand ein Glob mit `**`. PHP kennt das nicht als „beliebig
        // tief" — es verhält sich wie ein einfaches `*`. Gelesen wurden
        // dadurch nur die Seiten in einem Unterverzeichnis; Overview.vue und
        // PanelLayout.vue lagen außerhalb und wären nie geprüft worden. Der
        // Test wäre grün gewesen und hätte nichts bedeutet.
        $this->assertGreaterThan(8, count($files), 'Es werden kaum Vue-Dateien gelesen — dann prüft dieser Test nichts.');

        foreach ($files as $path) {
            $template = $this->template((string) file_get_contents($path));

            foreach (explode("\n", $template) as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = sprintf('%s:%d  %s', $this->relative($path), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "docs/19 §3: dieses Wort ist verbraucht, es heißt %s.\n\n  %s",
            $instead,
            implode("\n  ", $found),
        ));
    }

    /**
     * Und der `<script>`-Block, denn dort steht inzwischen Anzeigetext.
     *
     * **Diese Zeile ist genau die, die der Kommentar oben angekündigt hat.**
     * Dort stand: Was in `<script>` als Zeichenkette steht, bleibt aussen vor,
     * „dort steht in diesem Projekt kein Anzeigetext, sondern er kommt vom
     * Server" — und weiter: *„Sollte sich das ändern, ist diese Zeile die
     * Stelle, an der es nachzuziehen ist."*
     *
     * Geändert hat es die erste Rückfrage per `confirm()`: „Die Sicherung …
     * einspielen?" — ein Satz, den ein Kunde liest, der in keinem Template
     * steht und den deshalb kein Lauf gesehen hat. Der Knopf daneben trug
     * dasselbe Wort und ist in der CI aufgefallen; der Satz nicht. **Ein
     * Wächter mit einer Annahme über den Ort ist ein Wächter mit einem toten
     * Winkel**, und der wächst mit dem Projekt.
     *
     * Gelesen werden Zeichenkettenliterale und Template-Literale ohne
     * Kommentare. Bezeichner können hier nicht anschlagen: Sie sind englisch
     * (`ClassNameTest`), und die Wortliste ist deutsch.
     */
    #[DataProvider('words')]
    public function test_no_vue_script_string_uses_a_spent_word(string $pattern, string $instead): void
    {
        $found = [];
        $files = $this->files(dirname(__DIR__, 2).'/resources/js', 'vue');
        $read = 0;

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('#<script[^>]*>(.*?)</script>#su', $source, $match) !== 1) {
                continue;
            }

            $script = (string) preg_replace('#/\*.*?\*/#su', '', $match[1]);
            $script = (string) preg_replace('#^\s*//.*$#mu', '', $script);

            preg_match_all('/\'[^\'\n]*\'|"[^"\n]*"|`[^`]*`/su', $script, $literals);

            foreach ($literals[0] as $literal) {
                $read++;

                if (preg_match($pattern, $literal) === 1) {
                    $found[] = sprintf('%s  %s', $this->relative($path), mb_substr($literal, 0, 90));
                }
            }
        }

        $this->assertGreaterThan(50, $read, 'Es werden kaum Literale gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "docs/19 §3: dieses Wort ist verbraucht, es heißt %s.\n\n  %s\n\n".
            'Steht es in einer Rückfrage oder Meldung, gehört es ersetzt. Steht es in einem '.
            'Bezeichner, ist der Bezeichner nicht englisch — dann meldet ClassNameTest dasselbe.',
            $instead,
            implode("\n  ", $found),
        ));
    }

    /**
     * Kein Emoji in der Oberfläche.
     *
     * **Warum das eine Regel ist.** Im Passwortfeld standen 👁 und 🙈. Ein
     * Emoji wird von der Schriftart des Betriebssystems gezeichnet und sieht
     * auf jeder Plattform anders aus — auf einem Server mit dünner
     * Schriftausstattung mitunter als leeres Rechteck. Es nimmt keine
     * Textfarbe an und steht damit neben einer Gestaltung, in der Farbe etwas
     * bedeutet (§7.2). Und der Affe, der sich die Augen zuhält, war ein Witz
     * an einer Stelle, an der jemand ein Passwort für ein Kundenkonto setzt.
     *
     * **Die Regel lautet „Emoji und nicht ASCII".** `\p{Emoji}` allein reicht
     * nicht: Ziffern, `#` und `*` tragen diese Eigenschaft ebenfalls, weil sie
     * die Grundlage der Tastenkappen-Emoji sind — der Test hätte jede Zahl in
     * der Oberfläche gemeldet. Umgekehrt sind ✓, ✗, — und · keine Emoji,
     * sondern Schriftzeichen; sie bleiben erlaubt und tragen die Prüfliste.
     *
     * Zeichen wie ⚠ werden erfasst, obwohl sie ohne Variantenselektor als Text
     * gezeichnet werden. Das ist gewollt: Ein Warndreieck gehört als SVG in
     * die Oberfläche, nicht als Schriftzeichen mit ungewisser Darstellung.
     */
    public function test_no_vue_template_uses_an_emoji(): void
    {
        $found = [];
        $emoji = '/(?=\p{Emoji})[^\x00-\x7F]/u';

        foreach ($this->files(dirname(__DIR__, 2).'/resources/js', 'vue') as $path) {
            foreach (explode("\n", $this->template((string) file_get_contents($path))) as $number => $line) {
                if (preg_match($emoji, $line) === 1) {
                    $found[] = sprintf('%s:%d  %s', $this->relative($path), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "Emoji in der Oberfläche:\n  %s\n\n".
            'Ein Zeichen, das die Oberfläche selbst zeichnet — SVG mit `currentColor` — '.
            'sieht überall gleich aus und erbt Farbe und Größe. Siehe Components/EyeIcon.vue.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Eine Beschriftung ist kein Satzteil.
     *
     * **Aufgefallen ist das auf dem Zielserver**, im Abnahmelauf für P3: „Das
     * Abonnement ist wird angelegt — daran lässt sich nichts ändern."
     * `SubscriptionStatus::label()` liefert „wird angelegt" — richtig für eine
     * Spalte, falsch hinter „ist". Drei der vier Zustände passten in den
     * Satzrahmen, der vierte nicht, und weil in diesem Zustand sonst niemand
     * eine Domain anlegt, hat es kein Test und kein Blick in die Oberfläche
     * gezeigt.
     *
     * Geprüft wird deshalb die Sache und nicht der eine Fall: Ein Verb im
     * Satzrahmen, gefolgt von einem Platzhalter, der aus einer Beschriftung
     * gefüllt wird. Wer das braucht, schreibt das Verb in den Aufzählungstyp —
     * siehe `SubscriptionStatus::sentence()`.
     */
    public function test_no_label_is_pushed_into_a_sentence(): void
    {
        $found = [];

        // Die Verben, mit denen ein solcher Rahmen anfängt. Nach ihnen darf
        // kein Platzhalter stehen, der eine Beschriftung aufnimmt.
        $frame = '/\b(ist|war|wird|sind|waren|hat|wurde)\s+%s/u';

        foreach ($this->files(dirname(__DIR__, 2).'/app', 'php') as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                if (preg_match($frame, $line) === 1 && str_contains($line, 'label()')) {
                    $found[] = sprintf('%s:%d  %s', $this->relative($path), $number + 1, trim($line));
                }
            }
        }

        $this->assertSame([], $found, sprintf(
            "Eine Beschriftung steht in einem Satzrahmen:\n  %s\n\n".
            'Das ergibt Deutsch wie „Das Abonnement ist wird angelegt". Das Verb gehört '.
            'in den Aufzählungstyp, nicht in den Rahmen des Aufrufers.',
            implode("\n  ", $found),
        ));
    }

    public function test_the_list_matches_the_document(): void
    {
        $document = (string) file_get_contents(dirname(__DIR__, 2).'/docs/19-sprache-der-oberflaeche.md');

        // Die Gegenprobe zu docs/19 §5: Ein Eintrag gehört an drei Stellen
        // gleichzeitig. Zwei davon stehen hier — die Tabelle im Dokument und
        // die Liste in diesem Test. Läuft eine der beiden weg, ist die Vorgabe
        // wieder das, was sie vor diesem Test war: eine Absichtserklärung.
        $missing = [];

        foreach (array_keys(self::words()) as $word) {
            if (! str_contains($document, $word)) {
                $missing[] = $word;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Diese Wörter stehen im Test, aber nicht mehr in docs/19 §3:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = $this->files(dirname(__DIR__, 2).'/app', 'php');

        $this->assertGreaterThan(20, count($files), 'Es werden kaum PHP-Dateien gelesen — dann prüft dieser Test nichts.');

        return $files;
    }

    /**
     * Alle Dateien einer Endung unterhalb eines Verzeichnisses, beliebig tief.
     *
     * @return list<string>
     */
    private function files(string $root, string $extension): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Nur die Zeichenkettenliterale einer Datei, mit Zeilennummer.
     *
     * Über `token_get_all`, nicht über einen Ausdruck auf dem Text: Ein Muster
     * über die Datei fände jeden Kommentar mit, und Kommentare sind
     * ausdrücklich ausgenommen.
     *
     * @return array<int, string>
     */
    private function stringLiterals(string $path): array
    {
        $literals = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $literals[$token[2]] = $token[1];
            }
        }

        return $literals;
    }

    /** Der `<template>`-Block ohne HTML-Kommentare. */
    private function template(string $source): string
    {
        if (preg_match('#<template>(.*)</template>#su', $source, $match) !== 1) {
            return '';
        }

        return (string) preg_replace('/<!--.*?-->/su', '', $match[1]);
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }
}
