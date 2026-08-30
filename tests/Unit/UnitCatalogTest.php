<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Catalog;
use SrvPanel\Agent\Ops\ServiceAction;
use SrvPanel\Agent\Ops\SftpAccess;

/**
 * Der Katalog der Units — gegen die Paketierung und gegen die Positivliste.
 *
 * ## Was er hält
 *
 * Bis zum 30. August 2026 standen Unitnamen in zehn Dateien, und neun der
 * eigenen zwölf standen in keiner Anzeige. Der Katalog ist die eine Stelle;
 * damit sie eine bleibt, wird sie hier in **beide** Richtungen gehalten —
 * gegen `packaging/systemd/` und gegen {@see ServiceAction}.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * Die Gegenrichtung ist die, an der ein toter Eintrag wirklich entsteht: Bei
 * einer Umbenennung trägt man den neuen Namen nach, die erste Richtung ist
 * wieder grün, und der alte bleibt liegen.
 *
 * ## Warum die Erlaubnis gefragt und nicht nachgebaut wird
 *
 * {@see ServiceAction::allows()} ist die Entscheidung, die im Betrieb fällt.
 * Ein Wächter, der die Regel zum zweiten Mal aufschreibt, prüft seine eigene
 * Abschrift.
 */
final class UnitCatalogTest extends TestCase
{
    /** Die Units, die das Paket wirklich ablegt. */
    private static function packaged(): array
    {
        $verzeichnis = dirname(__DIR__, 2).'/packaging/systemd';
        $dateien = scandir($verzeichnis);

        self::assertIsArray($dateien);

        $units = array_values(array_filter(
            $dateien,
            static fn (string $name): bool => str_ends_with($name, '.service') || str_ends_with($name, '.timer'),
        ));

        sort($units);

        return $units;
    }

    public function test_the_catalogue_knows_every_packaged_unit(): void
    {
        $fehlen = array_diff(self::packaged(), Catalog::OWN);

        $this->assertSame(
            [],
            array_values($fehlen),
            'Das Paket liefert eine Unit, die der Katalog nicht kennt — sie taucht in keiner Anzeige auf.',
        );
    }

    /**
     * Die Gegenrichtung — hier entsteht der tote Eintrag.
     */
    public function test_every_unit_the_catalogue_names_is_packaged(): void
    {
        $ueberzaehlig = array_diff(Catalog::OWN, self::packaged());

        $this->assertSame(
            [],
            array_values($ueberzaehlig),
            'Der Katalog nennt eine eigene Unit, die das Paket nicht ablegt — auf dem Server meldet sie „nicht installiert".',
        );
    }

    /**
     * Ohne diese Zahl wären beide Richtungen auch dann grün, wenn Katalog und
     * Paketierung zugleich leer wären.
     */
    public function test_the_comparison_has_something_to_compare(): void
    {
        $this->assertCount(12, Catalog::OWN, 'Acht Dienste und vier Timer — gemessen an packaging/systemd.');
        $this->assertCount(12, self::packaged());
    }

    public function test_what_the_catalogue_calls_controlled_is_allowed(): void
    {
        foreach (Catalog::all() as $zeile) {
            if (! $zeile['controlled']) {
                continue;
            }

            $this->assertTrue(
                ServiceAction::allows($zeile['unit']),
                sprintf('%s gilt im Katalog als steuerbar, und ServiceAction lehnt es ab.', $zeile['unit']),
            );
        }
    }

    public function test_what_the_catalogue_does_not_control_is_denied(): void
    {
        foreach (Catalog::all() as $zeile) {
            if ($zeile['controlled']) {
                continue;
            }

            $this->assertFalse(
                ServiceAction::allows($zeile['unit']),
                sprintf('%s soll nicht steuerbar sein, und ServiceAction lässt es durch.', $zeile['unit']),
            );
        }
    }

    /**
     * Die beiden, bei denen ein Durchlassen den Server unerreichbar machte.
     *
     * Sie stehen hier namentlich und nicht nur in der Schleife darüber: Fiele
     * der Katalogeintrag weg, prüfte die Schleife sie nicht mehr und bliebe
     * grün.
     */
    public function test_neither_ssh_nor_cron_can_ever_be_controlled(): void
    {
        foreach (['ssh.service', 'sshd.service', 'cron.service', 'crond.service'] as $unit) {
            $this->assertFalse(ServiceAction::allows($unit), $unit.' darf nie steuerbar sein.');
            $this->assertFalse(Catalog::controls($unit), $unit.' steht im Katalog als steuerbar.');
        }
    }

    public function test_the_sftp_names_come_from_where_they_were_measured(): void
    {
        $this->assertSame(
            SftpAccess::UNITS,
            array_keys(Catalog::foreign()['sftp']),
            'Die SFTP-Namen sind abgeschrieben statt gelesen — dann laufen die beiden Listen auseinander.',
        );

        $quelle = file_get_contents(dirname(__DIR__, 2).'/agent/src/Catalog.php');
        $this->assertIsString($quelle);
        $this->assertStringNotContainsString(
            "'ssh.service'",
            $quelle,
            'Der Katalog schreibt den Namen selbst hin, statt ihn von SftpAccess zu nehmen.',
        );
    }

    public function test_no_unit_stands_in_the_catalogue_twice(): void
    {
        $units = array_column(Catalog::all(), 'unit');

        $this->assertSame(
            array_values(array_unique($units)),
            $units,
            'Eine Unit steht zweimal im Katalog — die Anzeige zeigte sie doppelt.',
        );
    }

    /**
     * Die Übersicht hält keine eigene Liste mehr.
     *
     * Bis zum 30. August 2026 standen dort drei Namen im Quelltext. Ein
     * Wächter über den Katalog allein hätte das nicht gesehen: Die Liste
     * daneben war vollständig richtig — sie war nur eine zweite.
     *
     * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
     * > beiden ist der Ort, an dem man nachsieht.**
     */
    public function test_the_overview_carries_no_list_of_its_own(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/OverviewController.php');

        $this->assertIsString($quelle);
        $this->assertStringContainsString('Catalog::essential()', $quelle);

        foreach (["'srvpanel-agentd.service'", "'nginx.service'", "'mariadb.service'"] as $name) {
            $this->assertStringNotContainsString(
                $name,
                $quelle,
                'Die Übersicht nennt '.$name.' wieder selbst — dann gibt es die Liste zum zweiten Mal.',
            );
        }
    }

    /**
     * **Ein toter Eintrag in der Positivliste, festgehalten und nicht behoben.**
     *
     * `ServiceAction::ALLOWED_UNITS` führt `php*-fpm.service`. Der Vergleicher
     * kennt Sterne aber nur am **Ende** eines Musters; ein Stern in der Mitte
     * fällt in den Gleichheitsvergleich, und keine Unit heisst wörtlich
     * `php*-fpm.service`. Der Eintrag hat also nie etwas erlaubt.
     *
     * > **Ein Muster in einer Positivliste, das die Liste selbst nicht auflösen
     * > kann, ist kein Eintrag — es ist eine Behauptung.**
     *
     * Gemessen ist auch, dass es niemandem fehlt: Der einzige Aufrufer von
     * `service.action` ist `Setup`, und der schickt keine PHP-Unit.
     *
     * Behoben wird das hier **nicht**, weil die beiden Wege in entgegengesetzte
     * Richtungen zeigen — den Eintrag zu streichen nimmt eine Erlaubnis weg,
     * den Vergleicher zu erweitern gibt eine dazu. Das entscheidet der
     * Betreiber. Bis dahin hält dieser Fall den gemessenen Zustand fest, damit
     * eine Änderung daran eine bewusste ist und keine Nebenwirkung.
     */
    public function test_the_php_fpm_pattern_currently_matches_nothing(): void
    {
        $this->assertFalse(ServiceAction::allows('php8.3-fpm.service'));
        $this->assertFalse(ServiceAction::allows('php8.4-fpm.service'));
        // Das Einzige, was dieses Muster je erlauben kann, ist eine Unit, die
        // wörtlich `php*-fpm.service` heisst — und die kann es nicht geben, weil
        // `Guard::unitName()` den Stern nicht durchlässt. Genau das macht den
        // Eintrag tot statt bloss ungenau.
        $this->assertTrue(
            ServiceAction::allows('php*-fpm.service'),
            'Der Wortlaut des Musters trifft sich selbst — fällt das weg, ist der Vergleicher ein anderer.',
        );
    }
}
