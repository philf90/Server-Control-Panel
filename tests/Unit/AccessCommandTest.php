<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Der Rückweg aus einer Netzbeschränkung — und die eine Stelle, die ihn prüft.
 *
 * ## Zwei Regeln, und die zweite ist die, die still bricht
 *
 * **Die erste:** `--clear` räumt ab, ohne zu fragen. Es ist der Rückweg für den
 * Fall, dass die gespeicherte Liste richtig ist und trotzdem falsch — ein
 * Anschluss mit neuer Adresse, ein Umzug, ein Anbieter, der neu nummeriert.
 * Käme dort ein Aussperrschutz dazwischen, wäre der Rückweg keiner.
 *
 * > **Ein Rückweg, der dieselbe Bedingung prüft wie der Hinweg, führt zurück an
 * > denselben Punkt.**
 *
 * **Die zweite:** Formular und Kommando stellen dieselbe Frage — ist das ein
 * brauchbares Netz? — und beide fragen `AdminNetwork`.
 * Baut eines davon seine eigene Prüfung, hat die Einstellung zwei Bedeutungen,
 * und welche die strengere ist, merkt niemand.
 *
 * > **Zwei Eingänge zu derselben Einstellung teilen ihre Prüfung, oder die
 * > Einstellung hat zwei Bedeutungen.**
 *
 * ## Warum das hier steht und nicht in einem Feature-Test
 *
 * Die **Wirkung** eines Artisan-Kommandos misst man mit `artisan()`, und das
 * braucht Laravel. Hier steht, was ohne Framework prüfbar ist — und das ist der
 * Teil, der dort, wo `vendor/` fehlt, sonst gar nicht auffiele.
 *
 * **Deshalb steht der Name oben ohne `{@see}`.** Pints
 * `fully_qualified_strict_types` zieht aus einer Referenz im Dokumentblock einen
 * `use`-Eintrag — und damit wäre dieser Wächter framework-abhängig und liefe
 * genau dort nicht mehr, wofür es ihn gibt.
 *
 * **Gemerkt hat das erst der Lauf des Bruchskripts.** Beim Schreiben war der
 * Wächter grün; Pint lief danach und fügte den Import hinzu. Was ins Repo geht,
 * ist die Fassung **nach** dem Formatierer.
 *
 * > **Ein Wächter, den man vor dem Formatierer prüft, ist nicht der, der ins
 * > Repo geht.**
 */
final class AccessCommandTest extends TestCase
{
    use WithoutPhpComments;

    /** Die eine Stelle, an der ein Netz geprüft wird. */
    private const CHECK = 'AdminNetwork::normalize(';

    /**
     * Beide Eingänge fragen dieselbe Stelle.
     *
     * @return list<array{0: string}>
     */
    public static function entrances(): array
    {
        return [
            ['app/Console/Commands/Access.php'],
            ['app/Http/Controllers/AccessSettingsController.php'],
        ];
    }

    #[DataProvider('entrances')]
    public function test_both_entrances_ask_the_same_place(string $relative): void
    {
        $source = $this->withoutComments($this->read($relative));

        $this->assertStringContainsString(self::CHECK, $source, sprintf(
            '%s prüft ein Netz nicht über AdminNetwork::normalize(). Baut ein Eingang seine eigene '
            .'Prüfung, hat die Einstellung zwei Bedeutungen — und welche die strengere ist, merkt '
            .'niemand.',
            $relative,
        ));

        $this->assertStringNotContainsString('Cidr::normalize(', $source, sprintf(
            '%s greift an der Politik vorbei auf die reine Rechnung. Cidr lässt `0.0.0.0/0` durch; '
            .'für eine Anmeldebeschränkung beschränkt das nichts.',
            $relative,
        ));
    }

    /**
     * `--clear` prüft nicht, ob die Liste den Aufrufer trägt.
     *
     * **Der Fall, für den es das Kommando gibt.** Ein Aussperrschutz an dieser
     * Stelle machte aus dem Rückweg einen zweiten Hinweg.
     */
    public function test_clearing_asks_no_lockout_question(): void
    {
        $source = $this->withoutComments($this->read('app/Console/Commands/Access.php'));

        $this->assertStringContainsString("option('clear')", $source,
            'Das Kommando kennt --clear nicht mehr — dann gibt es keinen Rückweg.');

        $this->assertStringNotContainsString('AdminNetwork::covers(', $source,
            'Das Kommando fragt den Aussperrschutz. Wer es aufruft, sitzt auf dem Server; die Frage '
            .'„deckt diese Liste deine Adresse" hat für ihn keine sinnvolle Antwort — und mit ihr '
            .'wäre der Rückweg keiner.');
    }

    /**
     * Und jede Änderung von der Kommandozeile steht im Protokoll.
     *
     * **Ein Weg, der an der Oberfläche vorbeiführt, gehört erst recht ins
     * Protokoll.** Ohne diesen Eintrag liesse sich eine Netzbeschränkung
     * spurlos aufheben — von jemandem mit SSH, also genau von dem, dessen
     * Handeln man später nachlesen will.
     */
    public function test_a_change_from_the_command_line_is_recorded(): void
    {
        $source = $this->withoutComments($this->read('app/Console/Commands/Access.php'));

        $this->assertMatchesRegularExpression(
            "/->record\(\s*'settings\.access'/",
            $source,
            'Das Kommando schreibt keinen Protokolleintrag mehr.',
        );
    }

    /**
     * Das Kommando steht in der Liste des Wrappers.
     *
     * **Sonst gibt es das Kommando auf dem Server nicht.** `srvpanel admin`
     * hat genau so gefehlt, und es fiel lange nicht auf, weil niemand ein Konto
     * über die Kommandozeile anlegte — wer es tippte, bekam „Command not
     * defined".
     *
     * `PackagingTest` hält das für alle Kommandos und braucht Laravel; hier
     * steht der eine Fall, der ohne Framework prüfbar ist und der neu ist.
     */
    public function test_the_wrapper_knows_the_command(): void
    {
        $wrapper = $this->read('packaging/bin/srvpanel');

        $this->assertMatchesRegularExpression(
            '/\|access\||\|access\)/',
            $wrapper,
            'packaging/bin/srvpanel kennt „access" nicht. Auf dem Server gäbe es das Kommando dann '
            .'nicht — und der Rückweg aus einer Netzbeschränkung wäre wieder nur ein '
            .'tinker-Einzeiler.',
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($path, $relative.' gibt es nicht mehr.');

        return (string) file_get_contents($path);
    }
}
