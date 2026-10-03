<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DiagnoseReport;
use App\Mail\QuotaWarning;
use App\Mail\TestMessage;
use App\Support\Settings\BrandSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Die Unterschrift einer Mail ist eine, die ein Mailprogramm erkennt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Beobachtung 2 aus dem Lauf für B6 (`docs/140 §7`, 3. Oktober 2026). In der
 * Quelle der Testmail stand die Unterschrift so da:
 *
 *     Protokoll des Panels nach: Dort steht, wer sie ausgelöst hat.
 *     --
 *     Muster Hosting
 *
 * Zwei Dinge fehlten. Die Trennzeile lautet nach RFC 3676 §4.3 `-- `, mit
 * Leerzeichen; ohne setzen Mailprogramme die Unterschrift nicht ab und lassen
 * sie beim Antworten im Zitat. Und die Leerzeile davor stand in der Vorlage
 * der Unterschrift und kam nie an: Laravel schneidet jeder gerenderten Ansicht
 * den Leerraum am Anfang ab (`ltrim(ob_get_clean())` in `PhpEngine`), auch
 * einer eingebundenen. Entschieden hat der Betreiber am selben Tag, beides mit
 * `0.9.0-rc.12` zu bauen.
 *
 * > **Eine Leerzeile am Anfang einer eingebundenen Vorlage kommt nie an —
 * > Laravel kürzt jede gerenderte Ansicht vorn.**
 *
 * ## Was er hält
 *
 * **An der Wirkung:** Jede der drei Mails trägt genau eine Trennzeile `-- `,
 * davor eine Leerzeile und danach den Namen der Marke, und keine Zeile `--`
 * ohne Leerzeichen. **An der Vorlage:** Jede Vorlage, die die Unterschrift
 * einbindet, lässt davor eine Zeile frei. Das gilt auch für die Vorlage einer
 * Mail, die es heute noch nicht gibt und die der erste Fall nicht rendert.
 */
final class MailSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mail_ends_with_a_signature_a_mail_program_recognises(): void
    {
        app(Settings::class)->saveBrand(new BrandSettings(name: 'Muster Hosting', footer: 'Betrieben von der Muster Hosting GmbH'));

        $texte = [
            'Betreiber' => (new DiagnoseReport([['label' => 'Ein Befund.', 'subject' => 'web-1', 'detail' => 'Detail', 'since' => '03.10.2026 17:00']]))->render(),
            'Kunde' => (new QuotaWarning('kunde-web', [['label' => 'Der Verkehr liegt über dem Kontingent.', 'detail' => '12 GB > 10 GB']]))->render(),
            'Probe' => (new TestMessage('Administrator', '2026-10-03 17:39:54'))->render(),
        ];

        foreach ($texte as $welche => $text) {
            $zeilen = explode("\n", $text);
            $trenner = array_keys($zeilen, '-- ', true);

            self::assertNotContains('--', $zeilen, $welche.': Die Trennzeile steht ohne Leerzeichen da. Ein Mailprogramm erkennt nur „-- " als Beginn der Unterschrift (RFC 3676 §4.3).');
            self::assertCount(1, $trenner, $welche.': Erwartet ist genau eine Trennzeile „-- ".');

            $stelle = $trenner[0];

            self::assertSame('', $zeilen[$stelle - 1] ?? null, $welche.': Vor der Unterschrift fehlt die Leerzeile. Eine Leerzeile am Anfang von mail.signature kommt nie an, Laravel kürzt jede gerenderte Ansicht vorn; sie gehört in die Vorlage, die einbindet.');
            self::assertSame('Muster Hosting', $zeilen[$stelle + 1] ?? null, $welche.': Nach der Trennzeile steht nicht der Name der Marke.');
        }
    }

    public function test_every_view_that_includes_the_signature_leaves_a_line_before_it(): void
    {
        $gefunden = [];
        $fehler = [];

        $dateien = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

        /** @var SplFileInfo $datei */
        foreach ($dateien as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }

            // Blade entfernt Kommentare vor allem anderen und lässt den
            // Zeilenumbruch dahinter stehen; dieselbe Regel hier.
            $quelle = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($datei->getPathname()));
            $zeilen = explode("\n", $quelle);
            $name = substr($datei->getPathname(), strlen(base_path()) + 1);

            foreach ($zeilen as $nummer => $zeile) {
                if (preg_match("/^\\s*@include\\s*\\(\\s*'mail\\.signature'/", $zeile) !== 1) {
                    continue;
                }

                $gefunden[] = $name;

                // Ohne Zeilennummer: Die Kommentare sind abgestreift, und ein
                // mehrzeiliger verschiebt jede Nummer dahinter.
                if ($nummer === 0 || trim($zeilen[$nummer - 1]) !== '') {
                    $fehler[] = sprintf('%s, davor steht „%s"', $name, trim($zeilen[$nummer - 1] ?? ''));
                }
            }
        }

        self::assertGreaterThanOrEqual(3, count($gefunden), 'Die drei Mailvorlagen binden die Unterschrift nicht mehr ein, oder der Leser findet sie nicht.');
        self::assertSame([], $fehler, "Vor der Unterschrift fehlt die Leerzeile. Sie gehört in die einbindende Vorlage, weil Laravel jede gerenderte Ansicht vorn kürzt:\n".implode("\n", $fehler));
    }
}
