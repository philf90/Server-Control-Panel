<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DiagnoseReport;
use App\Mail\QuotaWarning;
use App\Mail\TestMessage;
use App\Support\Settings\BrandSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eine Textmail gibt aus, was da steht — ohne HTML-Maskierung.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Befund 2 aus dem Abnahmelauf von „Platte voll" (`docs/137 §7`, 27. September
 * 2026): Die Mail über die aufgebrauchten Inodes trug den Satz
 *
 *     … scheitert jede neue Datei mit „No space left on device&quot;, auch …
 *
 * Blades `{{ }}` ruft `e()` und damit `htmlspecialchars()`, und das ist für eine
 * HTML-Seite richtig und für eine Textmail falsch: Dort steht die Maskierung
 * wörtlich im Postfach. Getroffen hat es jede Ausgabe der vier Textvorlagen —
 * das Anführungszeichen im Satz über die Inodes war bloss die erste, die
 * jemand gesehen hat. Ein Kundenname mit `&`, ein Markenname wie „Müller &
 * Söhne", ein Detail mit `<` hätten dasselbe getan.
 *
 * > **Eine Maskierung, die für ein Format richtig ist, ist im anderen ein
 * > Fehler — und sie fällt erst auf, wenn ein Wert das Zeichen trägt.**
 *
 * ## Was er hält
 *
 * **Jede Mail dieses Panels ist reiner Text**, und in den Vorlagen, die sie
 * erreicht — samt denen, die sie mit `@include` holt —, steht keine
 * maskierende Ausgabe. Und an der Wirkung: Drei Mails mit Werten, die jedes der
 * fünf Zeichen tragen, kommen so an, wie sie geschrieben sind.
 *
 * **Warum die erste Regel die Voraussetzung der zweiten ist.** Die
 * ungefilterte Ausgabe ist nur in Text richtig. Käme eine HTML-Mail dazu und
 * holte sich `mail.signature`, stünde der Markenname des Betreibers ungefiltert
 * in einem HTML-Dokument. Wer eine HTML-Mail baut, sieht deshalb hier Rot und
 * nicht erst im Postfach eines Kunden.
 */
final class PlainTextMailTest extends TestCase
{
    use RefreshDatabase;

    /** Die fünf Zeichen, die `htmlspecialchars()` ersetzt. */
    private const ZEICHEN = '"&\'<>';

    /** Was an ihrer Stelle stünde. */
    private const MASKEN = ['&quot;', '&amp;', '&#039;', '&lt;', '&gt;'];

    public function test_every_mail_is_plain_text(): void
    {
        $inhalte = 0;

        foreach ($this->mailables() as $pfad => $quelle) {
            preg_match_all('/new\s+Content\s*\((.*?)\)\s*;/s', $quelle, $treffer);

            foreach ($treffer[1] as $argumente) {
                $inhalte++;

                self::assertMatchesRegularExpression(
                    '/^\s*text\s*:/',
                    $argumente,
                    $pfad.' baut eine Mail, die nicht reiner Text ist. Die Vorlagen unter mail.* geben ungefiltert aus — in HTML wäre das eine Lücke.',
                );
                self::assertDoesNotMatchRegularExpression('/\b(view|html|markdown|htmlString)\s*:/', $argumente, $pfad);
            }
        }

        self::assertGreaterThanOrEqual(3, $inhalte, 'Die Mails dieses Panels sind nicht gefunden worden.');
    }

    public function test_no_text_view_escapes_what_it_prints(): void
    {
        $fehler = [];
        $gelesen = [];

        foreach ($this->textViews() as $name => $quelle) {
            $gelesen[] = $name;

            // Blade entfernt Kommentare vor allem anderen; dieselbe Regel hier.
            $ohne = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $quelle);

            foreach (explode("\n", $ohne) as $zeile) {
                if (preg_match('/(?<!@)\{\{/', $zeile) === 1) {
                    $fehler[] = sprintf('%s: %s', $name, trim($zeile));
                }
            }
        }

        // Die Untergrenze nennt die Vorlage, an der der Befund stand, und die
        // Unterschrift, die nur über `@include` erreicht wird.
        self::assertContains('mail.diagnose', $gelesen, 'Die Vorlage der Betreibermail ist nicht gelesen worden.');
        self::assertContains('mail.signature', $gelesen, 'Die Unterschrift steht nur im @include — der Leser folgt ihm nicht.');
        self::assertGreaterThanOrEqual(4, count($gelesen));

        self::assertSame([], $fehler, "Eine maskierende Ausgabe schreibt in einer Textmail &quot; statt eines Anführungszeichens:\n".implode("\n", $fehler));
    }

    /**
     * Und an der Wirkung: Jede der drei Mails trägt die fünf Zeichen so, wie
     * sie geschrieben sind — in jeder Ausgabe, die ein Wert erreicht.
     */
    public function test_a_value_arrives_as_it_was_written(): void
    {
        app(Settings::class)->saveBrand(new BrandSettings(name: 'Müller & Söhne', footer: 'Betrieben von "Müller & Söhne" <info>'));

        $befund = [
            'label' => 'Belegung eines Dateisystems: Sind sie es ganz, scheitert jede neue Datei mit „No space left on device".',
            'subject' => "Einhängepunkt /srv/o'brien",
            'detail' => '100,0 % der Inodes vergeben, 0 frei. <'.self::ZEICHEN.'>',
            'since' => '27.09.2026 19:33',
        ];

        $texte = [
            'Betreiber' => (new DiagnoseReport([$befund]))->render(),
            'Kunde' => (new QuotaWarning('abo & co', [['label' => 'Der Verkehr liegt über dem Kontingent.', 'detail' => '"12 GB" > 10 GB']]))->render(),
            'Probe' => (new TestMessage("O'Brien & <Admin>", '27.09.2026 21:00'))->render(),
        ];

        foreach ($texte as $welche => $text) {
            foreach (self::MASKEN as $maske) {
                self::assertStringNotContainsString($maske, $text, $welche.': Die Textmail trägt die Maskierung '.$maske.' wörtlich.');
            }

            self::assertStringContainsString('Müller & Söhne', $text, $welche.': Die Unterschrift kommt nicht, wie sie geschrieben ist.');
            self::assertStringContainsString('Betrieben von "Müller & Söhne" <info>', $text, $welche);
        }

        self::assertStringContainsString('„No space left on device".', $texte['Betreiber'], 'Der Satz aus dem Abnahmelauf.');
        self::assertStringContainsString("Einhängepunkt /srv/o'brien", $texte['Betreiber']);
        self::assertStringContainsString('<'.self::ZEICHEN.'>', $texte['Betreiber']);
        self::assertStringContainsString('abo & co', $texte['Kunde']);
        self::assertStringContainsString('"12 GB" > 10 GB', $texte['Kunde']);
        self::assertStringContainsString("O'Brien & <Admin>", $texte['Probe']);
    }

    /**
     * Jede Vorlage, die eine Textmail erreicht — über `Content(text:)` und über
     * jedes `@include` darin.
     *
     * @return array<string, string> Name => Quelle
     */
    private function textViews(): array
    {
        $offen = [];

        foreach ($this->mailables() as $quelle) {
            preg_match_all("/text\\s*:\\s*'([\\w.\\-]+)'/", $quelle, $treffer);
            array_push($offen, ...$treffer[1]);
        }

        $gelesen = [];

        while ($offen !== []) {
            $name = array_shift($offen);

            if (array_key_exists($name, $gelesen)) {
                continue;
            }

            $datei = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');

            self::assertFileExists($datei, $name.' wird von einer Mail genannt und liegt nicht da.');

            $gelesen[$name] = (string) file_get_contents($datei);

            preg_match_all("/@include\\s*\\(\\s*'([\\w.\\-]+)'/", $gelesen[$name], $einbindungen);
            array_push($offen, ...$einbindungen[1]);
        }

        return $gelesen;
    }

    /**
     * @return array<string, string> Pfad => Quelle
     */
    private function mailables(): array
    {
        $quellen = [];

        foreach (glob(app_path('Mail/*.php')) ?: [] as $datei) {
            $quellen['app/Mail/'.basename($datei)] = (string) file_get_contents($datei);
        }

        return $quellen;
    }
}
