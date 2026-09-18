<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutMarkupComments;

/**
 * Die beiden Listen der Sicherungen benutzen dieselben Wörter und sagen, welche
 * Zeit welche ist.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Befund 6 des Nachlaufs zu P8** (`docs/121 §9`), gemessen am 18. September
 * 2026 auf `cloudsrv24`: Jede Zeile zeigt den Ablagenamen
 * `…-20260918-103020-…` und daneben die Spalte mit `12:30:20` — derselbe
 * Augenblick, **zwei Stunden auseinander**, und nichts sagt, dass das so
 * gemeint ist. `Backups.php` baut den Namen mit `gmdate()`, also UTC; die
 * Spalte geht über `Clock` in die eingestellte Zone. Beide sind für sich
 * richtig.
 *
 * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
 * > sondern eine widersprüchliche.** (`docs/91` Befund 5)
 *
 * ## Und beim Nachsehen stand daneben noch etwas
 *
 * Dieselben zwei Spalten hiessen in den beiden Listen **vier** verschiedene
 * Dinge: `Sicherung`/`Stand` und `Erstellt`/`Angelegt`. Der Kopf von
 * `BackupPick.vue` verlangt das Gegenteil — *„Wer diese hier ändert, sieht dort
 * nach."*
 *
 * ## Was er hält, und warum an der Zelle und nicht an der Kopfzeile
 *
 * Gesucht wird die Zelle, die den **Wert** zeigt (`storage_name`,
 * `created_at`), und von dort aus ihre Beschriftung. Ein Wächter über die
 * Kopfzeilen allein bliebe grün, wenn jemand die Spalten vertauschte — die
 * Wörter stünden ja weiterhin da.
 *
 * Dazu, dass jede Beschriftung einer gestapelten Zelle in ihrer Datei auch als
 * Kopfzeile vorkommt: Unter 720 px rendert `.stacks td::before` das
 * `data-column`, darüber steht das `<th>`, und wer eines von beiden umbenennt,
 * benennt sonst nur die halbe Tabelle um.
 *
 * ## Was er nicht hält
 *
 * Dass die Beschriftung zur **richtigen** Spalte gehört, wenn eine Zeile
 * Zellen überspringt — dafür bräuchte er eine Zuordnung `<td>` zu `<th>` über
 * die Stellung, und die trägt bei `v-if` auf einer Zelle nicht. Und ob der Satz
 * gelesen wird, sagt kein Test.
 */
final class BackupColumnTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Die Zelle, die einen Wert zeigt, und wie ihre Spalte heissen muss.
     *
     * @var array<string, string>
     */
    private const SPALTEN = [
        'storage_name' => 'Sicherung',
        'created_at' => 'Erstellt',
    ];

    /** @return list<string> */
    private function seiten(): array
    {
        return [
            'resources/js/Pages/Subscriptions/Backups.vue',
            'resources/js/Pages/Subscriptions/BackupPick.vue',
        ];
    }

    private function quelle(string $pfad): string
    {
        $roh = file_get_contents(dirname(__DIR__, 2).'/'.$pfad);

        $this->assertIsString($roh, sprintf('%s lässt sich nicht lesen.', $pfad));

        return $this->withoutMarkupComments($roh);
    }

    /**
     * Dieselbe Sache heisst in beiden Listen dasselbe.
     *
     * Gemessen an der Zelle, die den Wert zeigt, und nicht an der Kopfzeile:
     * Die Kopfzeilen allein blieben auch dann grün, wenn jemand die Spalten
     * vertauschte.
     */
    public function test_both_tables_use_the_same_words(): void
    {
        $falsch = [];
        $gefunden = 0;

        foreach ($this->seiten() as $pfad) {
            $quelle = $this->quelle($pfad);

            foreach (self::SPALTEN as $wert => $wort) {
                if (preg_match('/<td data-column="([^"]+)"[^>]*>\s*\{\{\s*[a-z]+\.'.$wert.'/', $quelle, $treffer) !== 1) {
                    continue;
                }

                $gefunden++;

                if ($treffer[1] !== $wort) {
                    $falsch[] = sprintf('%s: %s steht unter „%s" statt „%s"', basename($pfad), $wert, $treffer[1], $wort);
                }
            }
        }

        $this->assertSame(
            count($this->seiten()) * count(self::SPALTEN),
            $gefunden,
            'Es wurden nicht alle Zellen gefunden — dann prüft dieser Test weniger, als er behauptet.',
        );

        $this->assertSame([], $falsch, sprintf(
            "Dieselbe Sache heisst in den beiden Listen der Sicherungen verschieden:\n  %s\n\n"
            .'Die eine Seite ist die Zwillingsseite der anderen, und ihr eigener Kopf sagt es: '
            .'„Wer diese hier ändert, sieht dort nach."',
            implode("\n  ", $falsch),
        ));
    }

    /** Jede Beschriftung einer gestapelten Zelle kommt in ihrer Datei als Kopfzeile vor. */
    public function test_every_column_label_matches_a_header(): void
    {
        $ohne = [];

        foreach ($this->seiten() as $pfad) {
            $quelle = $this->quelle($pfad);

            preg_match_all('/<th>([^<]+)<\/th>/', $quelle, $kopfzeilen);
            preg_match_all('/data-column="([^"]+)"/', $quelle, $spalten);

            $this->assertNotSame([], $kopfzeilen[1], sprintf('%s hat keine Kopfzeilen — dann prüft dieser Test nichts.', $pfad));

            foreach (array_unique($spalten[1]) as $spalte) {
                if (! in_array($spalte, $kopfzeilen[1], true)) {
                    $ohne[] = basename($pfad).': '.$spalte;
                }
            }
        }

        $this->assertSame([], $ohne, sprintf(
            "Diese Beschriftungen gestapelter Zellen haben keine Kopfzeile desselben Namens:\n  %s\n\n"
            .'Unter 720 px rendert `.stacks td::before` das `data-column`, darüber steht das `<th>` — '
            .'wer eines umbenennt, benennt sonst nur die halbe Tabelle um.',
            implode("\n  ", $ohne),
        ));
    }

    /**
     * Und beide sagen, welcher Zeitpunkt welcher ist.
     *
     * **Beide und nicht eine.** `LogFooterTest` war im September grün, während
     * derselbe Befund eine Seite weiter offenstand — seitdem hält er Paare.
     */
    public function test_both_tables_say_which_timestamp_is_which(): void
    {
        foreach ($this->seiten() as $pfad) {
            $quelle = $this->quelle($pfad);

            $this->assertMatchesRegularExpression(
                '/note="[^"]*UTC[^"]*Erstellt[^"]*"/',
                $quelle,
                sprintf(
                    '%s sagt nicht, dass der Ablagename UTC trägt und die Spalte Erstellt die '
                    .'eingestellte Zone. Beide Zeitpunkte stehen in derselben Zeile und gehen um '
                    .'Stunden auseinander; ohne den Satz ist das eine widersprüchliche Auskunft.',
                    basename($pfad),
                ),
            );
        }
    }
}
