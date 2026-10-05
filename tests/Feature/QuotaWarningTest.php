<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Mail\QuotaWarning;
use App\Support\Diagnose\Checks\QuotaOverrun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Was die Mail an den Kunden über seine Kontingente sagt — B5, `docs/141 §0`.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Vor dem Abnahmelauf von B5 stand jede Zeile eines Befundes so da (gemessen
 * am 5. Oktober 2026 im Container):
 *
 *     - Die Datenbanken dieses Abonnements liegen zusammen über ihrem Kontingent.: 3 MB von 1 MB
 *
 * Ein Doppelpunkt hinter dem Punkt, 89 Zeichen, und darunter ein Absatz, der
 * für jedes Kontingent dasselbe sagte: „gemessen und nicht erzwungen — es wird
 * nichts abgeschaltet und nichts gesperrt". Für den Speicherplatz ist das
 * falsch, denn ihn erzwingt die Dateisystem-Quota. Der Absatz über die
 * Abrechnung des Traffics stand auch in Mails, in denen es um Traffic gar
 * nicht ging. Der Betreiber hat am selben Tag entschieden, alle drei zu
 * berichtigen.
 *
 * > **Eine Mail, die einen Satz für alle Fälle hat, hat ihn für einen davon
 * > falsch.**
 *
 * ## Was er hält
 *
 * Jeder Grund, der beim Kunden ankommen kann, hat eine Überschrift. Keine
 * Zeile ist länger als 77 Zeichen, und kein Satz endet in „.:". Die Absätze
 * folgen den Kontingenten, die in der Mail stehen, und zwar in beide
 * Richtungen: Wer einen Absatz bekommt, braucht ihn, und wer ihn nicht
 * braucht, bekommt ihn nicht.
 */
final class QuotaWarningTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Name, der die Einleitung bricht — gewöhnliche sind kürzer. */
    private const LANG = 'kunde-mit-einem-ziemlich-langen-namen.example.invalid';

    /**
     * Eine Mail mit diesen Gründen, wie der Mailkanal sie baut.
     *
     * Die Sätze kommen aus dem Katalog und nicht aus diesem Test — der Kunde
     * liest, was der Betreiber auf der Diagnoseseite liest.
     *
     * @param  non-empty-list<string>  $gruende
     */
    private function mail(array $gruende, string $abo = 'p1000'): QuotaWarning
    {
        return new QuotaWarning($abo, array_map(static fn (string $grund): array => [
            'reason' => $grund,
            'label' => FindingCheck::QuotaExceeded->sentence($grund),
            'detail' => $grund === 'disk_near_limit' ? '19.200 MB von 20.000 MB (96,0 %)' : '3.072 MB von 1.024 MB',
        ], $gruende));
    }

    /** Der Rumpf ohne Zeilenumbrüche — die Absätze sind gebrochen. */
    private static function fliesstext(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Die Gründe, die beim Kunden ankommen können.
     *
     * **Aus der Prüfung und dem Katalog und nicht aus einer Liste hier.**
     * `Unknown` wird nicht gemeldet (`Notices::dueAt()`); jeder andere Grund
     * von `quota.exceeded` kann in einer Mail stehen.
     *
     * @return non-empty-list<string>
     */
    private static function meldbar(): array
    {
        $gruende = array_values(array_filter(
            QuotaOverrun::REASONS[FindingCheck::QuotaExceeded->value],
            static fn (string $grund): bool => FindingCheck::QuotaExceeded->state($grund) !== FindingState::Unknown,
        ));

        self::assertGreaterThanOrEqual(4, count($gruende), 'Kaum Gründe gefunden — dann prüft dieser Wächter nichts.');

        return $gruende;
    }

    /**
     * Jeder Grund, der ankommen kann, hat eine Überschrift.
     *
     * `headline()` trägt kein `default`, mit Absicht: Ein neuer Grund ohne
     * Überschrift wirft dort, statt still „Kontingent" zu schreiben. Ohne diesen
     * Fall würfe er erst im Nachtlauf.
     */
    public function test_every_reason_a_customer_can_get_has_a_headline(): void
    {
        foreach (self::meldbar() as $grund) {
            $betreff = (string) $this->mail([$grund])->envelope()->subject;

            self::assertStringContainsString(QuotaWarning::headline($grund).': p1000', $betreff, $grund);
        }
    }

    /**
     * Der Betreff sagt, was los ist — und „fast" ist nicht „überschritten".
     *
     * Bis zum 5. Oktober 2026 stand über jeder dieser Mails „Kontingent
     * überschritten", auch über der Vorwarnung, die es erst seitdem gibt.
     */
    public function test_the_subject_says_what_happened(): void
    {
        $fast = (string) $this->mail(['disk_near_limit'])->envelope()->subject;

        self::assertStringContainsString('Speicherplatz fast ausgeschöpft: p1000', $fast);
        self::assertStringNotContainsString('überschritten', $fast);

        $zwei = (string) $this->mail(['databases_over', 'traffic_over'])->envelope()->subject;

        self::assertStringContainsString('Datenbankgröße überschritten und Traffic überschritten: p1000', $zwei);
    }

    /**
     * Keine Zeile ist länger als 77 Zeichen.
     *
     * Gezählt nach Zeichen und nicht nach Bytes: Ein Umlaut ist in UTF-8 zwei
     * davon, und `wordwrap()` hätte die Zeilen mit Umlauten zu früh gebrochen.
     * **Gemessen mit allen Gründen und einem langen Namen**, weil eine Mail mit
     * einem Grund und `p1000` jede Grenze einhält.
     */
    public function test_no_line_is_longer_than_77_characters(): void
    {
        $text = $this->mail(['disk_near_limit', 'databases_over', 'traffic_over'], self::LANG)->render();
        $zuLang = [];

        foreach (explode("\n", $text) as $zeile) {
            if (mb_strlen($zeile) > 77) {
                $zuLang[] = sprintf('%d: %s', mb_strlen($zeile), $zeile);
            }
        }

        self::assertStringContainsString(self::LANG, $text, 'Der lange Name steht nicht in der Mail — dann misst dieser Fall nichts.');
        self::assertSame([], $zuLang, "Diese Zeilen bricht ein Mailprogramm, wo es will:\n".implode("\n", $zuLang));
    }

    /** Kein Satz endet in „.:" — der Wert steht unter dem Satz und nicht hinter seinem Punkt. */
    public function test_no_sentence_ends_in_a_colon_after_its_full_stop(): void
    {
        $text = $this->mail(self::meldbar())->render();

        self::assertStringNotContainsString('.:', $text);
        self::assertStringContainsString('Gemessen: 3.072 MB von 1.024 MB', $text, 'Der gemessene Wert fehlt — dann misst dieser Fall nichts.');
    }

    /**
     * Die Absätze folgen den Kontingenten, die in der Mail stehen.
     *
     * Je Fall **beide Richtungen**: dass der Absatz kommt, wo er hingehört, und
     * dass er fehlt, wo er nicht hingehört. Der Fehler vom 5. Oktober war die
     * zweite Richtung — der Satz über das Erzwingen stand in jeder Mail.
     */
    public function test_the_paragraphs_follow_the_quotas_in_the_mail(): void
    {
        $platz = 'Den Speicherplatz begrenzt das Dateisystem';
        $rueckweg = sprintf('Die Warnung endet, wenn der belegte Platz unter %d %% des Kontingents fällt.', QuotaOverrun::DISK_RELEASE_PERCENT);
        $gemessen = 'gemessen und nicht erzwungen';
        $abrechnung = 'Beim Traffic zählt diese Zahl';

        $voll = self::fliesstext($this->mail(['disk_over'])->render());
        self::assertStringContainsString($platz, $voll);
        self::assertStringNotContainsString($rueckweg, $voll, 'Ein voller Platz endet nicht unter 90 % — er ist voll.');
        self::assertStringNotContainsString($gemessen, $voll, 'Den Platz erzwingt die Dateisystem-Quota.');
        self::assertStringNotContainsString($abrechnung, $voll, 'In dieser Mail geht es nicht um Traffic.');

        $fast = self::fliesstext($this->mail(['disk_near_limit'])->render());
        self::assertStringContainsString($platz, $fast);
        self::assertStringContainsString($rueckweg, $fast);

        $datenbanken = self::fliesstext($this->mail(['databases_over'])->render());
        self::assertStringContainsString('Die Datenbankgröße wird '.$gemessen, $datenbanken);
        self::assertStringNotContainsString($platz, $datenbanken);
        self::assertStringNotContainsString($abrechnung, $datenbanken);

        $traffic = self::fliesstext($this->mail(['traffic_over'])->render());
        self::assertStringContainsString('Der Traffic wird '.$gemessen, $traffic);
        self::assertStringContainsString($abrechnung, $traffic);
        self::assertStringNotContainsString($platz, $traffic);
    }
}
