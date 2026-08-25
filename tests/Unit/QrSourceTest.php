<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutMarkupComments;

/**
 * Der QR-Code und die Adresse darunter kommen aus **einer** Quelle — und der
 * Code folgt dem Thema nicht.
 *
 * ## Warum die eine Quelle
 *
 * `otpauth://`-Adressen sind lang, und ihre Teile entscheiden, ob ein Code
 * funktioniert: `secret`, `issuer`, `algorithm`, `digits`, `period`. Würde die
 * Oberfläche sie ein zweites Mal zusammensetzen, gäbe es zwei Fassungen — und
 * die falsche wäre **die im QR-Code**, weil niemand ihn abtippt und mit der
 * Textzeile vergleicht.
 *
 * > **Ein Weg, den niemand nachliest, ist der, an dem ein Fehler bleibt.**
 *
 * Gebaut wird die Adresse deshalb ausschliesslich vom Server
 * (`Totp::provisioningUri()`), und die Seite reicht denselben Wert an beide
 * Stellen.
 *
 * ## Und warum der Code dem Thema nicht folgt
 *
 * Ein QR-Code bleibt dunkel auf hell, auch wenn die Seite dunkel ist:
 * Invertiert scheitert er an vielen Lesegeräten. Das ist die eine Ausnahme von
 * „beide Themes entstehen zusammen", und sie ist an ihrer Marke in `app.css`
 * begründet.
 *
 * > **Eine Regel des Gestaltungssystems, die ein Gerät nicht liest, ist keine
 * > Gestaltung mehr, sondern ein Ausfall.**
 *
 * Gehalten wird sie hier als **Abwesenheit**: Die beiden Marken stehen im
 * hellen Block und dürfen im dunklen nicht noch einmal stehen. Eine Ausnahme,
 * die niemand prüft, ist beim nächsten Durchgang durch app.css weg — und zwar
 * mit der besten Absicht, weil jede andere Marke dort ihr Gegenstück hat.
 */
final class QrSourceTest extends TestCase
{
    use WithoutMarkupComments;

    /** Die Adresse wird in der Oberfläche nirgends zusammengesetzt. */
    public function test_the_interface_never_builds_the_uri_itself(): void
    {
        $funde = [];
        $dateien = 0;

        foreach ($this->frontend() as $pfad => $roh) {
            $dateien++;
            $quelle = $this->withoutMarkupComments($roh);

            // `otpauth` in einer Zeichenkette heisst: Hier entsteht eine Adresse.
            if (preg_match('/[\'"`]otpauth:/', $quelle) === 1) {
                $funde[] = $pfad;
            }
        }

        $this->assertGreaterThan(50, $dateien,
            'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $funde, sprintf(
            "Diese Dateien bauen eine otpauth-Adresse:\n  %s\n\n".
            'Sie kommt vom Server. Zwei Fassungen liefen auseinander, und die falsche wäre die '
            .'im QR-Code — weil niemand ihn abtippt.',
            implode("\n  ", $funde),
        ));
    }

    /** Code und Textzeile bekommen denselben Wert. */
    public function test_the_code_and_the_text_share_one_value(): void
    {
        $seite = $this->withoutMarkupComments($this->read('resources/js/Pages/Auth/TwoFactorSetup.vue'));

        $this->assertStringContainsString(':uri="props.uri"', $seite,
            'Der QR-Code bekommt nicht `props.uri`. Dann hat er eine eigene Quelle.');

        $this->assertStringContainsString('{{ props.uri }}', $seite,
            'Die Adresse steht nicht mehr als Text auf der Seite. Sie ist der Weg für alle, '
            .'die nicht fotografieren können — und die Gegenprobe zum Code.');
    }

    /**
     * Die Komponente rechnet nicht selbst.
     *
     * Reed-Solomon, Maskenwahl und Bitfolge schreibt man nicht selbst; Form,
     * Grösse und Farbe schon. Steht hier eines Tages eine eigene Kodierung,
     * ist das eine Entscheidung und keine Nebenwirkung.
     */
    public function test_the_component_only_draws(): void
    {
        $quelle = $this->withoutMarkupComments($this->read('resources/js/Components/QrCode.vue'));

        $this->assertStringContainsString("from 'uqr'", $quelle,
            'Die Komponente holt die Matrix nicht mehr aus uqr.');

        foreach (['fill=', 'stroke=', '#'] as $farbe) {
            $this->assertStringNotContainsString($farbe, $quelle, sprintf(
                'In der Komponente steht `%s`. Farbe kommt aus app.css — hier über `.qr-ground` '
                .'und `.qr-modules`.',
                $farbe,
            ));
        }
    }

    /**
     * Die Ausnahme steht im hellen Block und **nicht** im dunklen.
     */
    public function test_the_qr_colours_do_not_follow_the_theme(): void
    {
        $css = $this->read('resources/css/app.css');

        foreach (['--qr-dark', '--qr-light'] as $marke) {
            $this->assertSame(1, substr_count($css, $marke.':'), sprintf(
                'Die Marke `%s` ist %d mal gesetzt. Genau einmal ist richtig: Ein QR-Code bleibt '
                .'dunkel auf hell, auch im dunklen Theme — invertiert scheitert er an vielen '
                .'Lesegeräten.',
                $marke,
                substr_count($css, $marke.':'),
            ));
        }

        // Und die Gegenprobe: Eine gewöhnliche Marke hat sehr wohl zwei Fassungen.
        $this->assertGreaterThan(1, substr_count($css, '--bg:'),
            'Selbst `--bg` steht nur einmal — dann misst der Test darüber nichts, '
            .'weil dieses Stylesheet gar keine zwei Themes mehr hat.');
    }

    /**
     * Nur die eine Komponente fasst die Bibliothek an.
     *
     * Dieselbe Auflage, die `docs/51 §8.1` für CodeMirror stellt, und aus
     * demselben Grund: Eine Bibliothek, die an zwei Stellen benutzt wird, ist
     * beim nächsten Mal an drei — und dann entscheidet die Gewohnheit statt
     * eines Menschen.
     *
     * > **Eine Regel, die nie jemand gebrochen hat, sieht aus wie eine Regel
     * > und ist eine Gewohnheit.**
     */
    public function test_only_the_qr_component_touches_the_library(): void
    {
        $funde = [];
        $treffer = 0;

        foreach ($this->frontend() as $pfad => $roh) {
            if (! str_contains($this->withoutMarkupComments($roh), "'uqr'")) {
                continue;
            }

            $treffer++;

            if ($pfad !== 'resources/js/Components/QrCode.vue') {
                $funde[] = $pfad;
            }
        }

        $this->assertSame(1, $treffer,
            'Die Bibliothek wird an keiner oder an mehr als einer Stelle eingebunden — '
            .'im ersten Fall misst dieser Test nichts.');

        $this->assertSame([], $funde, sprintf(
            "Diese Dateien binden uqr ein:\n  %s\n\nNur QrCode.vue darf das.",
            implode("\n  ", $funde),
        ));
    }

    /**
     * Alle Dateien der Oberfläche, Pfad zu Inhalt.
     *
     * **`glob('**\/*')` tut es nicht** — PHPs `glob` steigt nicht in
     * Unterverzeichnisse ab, egal wie viele Sterne dastehen. Der erste Wurf
     * las damit **null** Dateien, und die Untergrenze oben hat es beim ersten
     * Lauf gemeldet.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     *
     * @return array<string, string>
     */
    private function frontend(): array
    {
        $wurzel = dirname(__DIR__, 2);
        $dateien = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel.'/resources/js', FilesystemIterator::SKIP_DOTS),
        ) as $datei) {
            if ($datei->isFile() && in_array($datei->getExtension(), ['vue', 'ts'], true)) {
                $dateien[substr($datei->getPathname(), strlen($wurzel) + 1)]
                    = (string) file_get_contents($datei->getPathname());
            }
        }

        ksort($dateien);

        return $dateien;
    }

    private function read(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad);
    }
}
