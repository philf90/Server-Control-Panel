<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DiagnoseReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Mail an den Betreiber sagt nichts über sich, was nicht stimmt.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Zwei Befunde aus dem Abnahmelauf von „Platte voll" (`docs/137 §7`,
 * 27. September 2026), beide in drei Mails desselben Laufs zu lesen:
 *
 * - **Befund 1:** „die nächtliche Bestandsdiagnose … meldet Folgendes" — in
 *   einer Mail, die um 18:18 Uhr kam. Seit `docs/136` schickt dieselbe Vorlage
 *   auch die Befunde der Messung alle fünf Minuten, und der Satz stammte aus
 *   der Zeit, als es nur die Nacht gab. Die Regel darunter („zwei Läufe
 *   hintereinander … in derselben Nacht") stimmte für diese Mails ebenso
 *   wenig.
 * - **Befund 3:** „auf der Seite „Bestand" im Panel" — eine Seite dieses Namens
 *   gibt es nicht; das Menü nennt sie „Diagnose". Er stand auf allen drei
 *   Fotos und ist erst beim Lesen der Vorlage nach dem Lauf aufgefallen.
 *
 * > **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
 * > verdeckt alles, was daneben steht.**
 *
 * ## Was er hält
 *
 * Der Satz, der den Absender nennt, nennt keine Tageszeit — er gilt für beide
 * Läufe. Und die Seite, auf die die Mail verweist, heisst so wie ihr Menüpunkt;
 * der Name kommt aus `PanelLayout.vue` und nicht aus diesem Test. Dass die
 * Zahlen der Regel zum Zeitgeber passen, hält `DiskCadenceTest`, weil dort die
 * Unit gelesen wird.
 */
final class OperatorMailTest extends TestCase
{
    // Die Unterschrift liest die Marke aus der Datenbank.
    use RefreshDatabase;

    /** Eine Zeile, wie die Messung der Dateisysteme sie meldet. */
    private const PLATTE = [
        'label' => 'Belegung eines Dateisystems: Das Dateisystem wird eng: gewarnt ab 85 %, entwarnt unter 80 %.',
        'subject' => 'Einhängepunkt /mnt/sp-platte',
        'detail' => '87,0 % belegt.',
        'since' => '27.09.2026 18:08',
    ];

    public function test_the_sentence_naming_the_sender_names_no_time_of_day(): void
    {
        $text = (new DiagnoseReport([self::PLATTE]))->render();

        $anfang = strstr($text, '- '.self::PLATTE['label'], true);

        self::assertIsString($anfang, 'Die Befundzeile steht nicht in der Mail — der Fall misst nichts.');
        self::assertStringContainsString('meldet Folgendes', $anfang, 'Der Satz, der den Absender nennt, ist nicht gefunden worden.');
        self::assertDoesNotMatchRegularExpression(
            '/nacht|nächtlich|täglich|abend|morgen/iu',
            $anfang,
            'Die Mail kommt aus der Nacht und aus der Messung alle fünf Minuten — der Satz über den Absender gilt für beide oder nennt keinen Zeitpunkt.',
        );
    }

    public function test_the_mail_names_the_page_as_the_menu_does(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/PanelLayout.vue'));

        self::assertSame(
            1,
            preg_match("/\\{\\s*name:\\s*'([^']+)',\\s*href:\\s*'\\/diagnose'/", $layout, $eintrag),
            'Der Menüpunkt der Diagnoseseite ist in PanelLayout.vue nicht gefunden worden.',
        );

        $text = (new DiagnoseReport([self::PLATTE]))->render();

        self::assertMatchesRegularExpression('/der\s+Seite\s+„([^"]+)"/u', $text, 'Die Mail verweist auf keine Seite.');

        preg_match('/der\s+Seite\s+„([^"]+)"/u', $text, $seite);

        self::assertSame($eintrag[1], $seite[1], 'Die Mail schickt den Betreiber auf eine Seite, die das Menü anders nennt.');
    }
}
