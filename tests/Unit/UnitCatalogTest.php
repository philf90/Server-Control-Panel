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
    /**
     * Die Units, die das Paket wirklich ablegt.
     *
     * Die Marke steht auf einer eigenen Zeile: In einem einzeiligen Block ist
     * `@return` Fliesstext, und die Angabe wäre damit weg — PHPStan meldet dann
     * „no value type specified", und zwar erst in der CI.
     *
     * @return list<string>
     */
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
     * Die Positivliste führt genau das, was benutzt wird.
     *
     * **Am 30. August 2026 vom Betreiber entschieden.** Bis dahin standen dort
     * vier Einträge; drei waren ungenutzt und einer wirkungslos.
     *
     * `php*-fpm.service` hat nie etwas erlaubt — der Vergleicher löst einen
     * Stern nur am Ende auf, und eine Unit, die wörtlich so heisst, lässt
     * `Guard::unitName()` nicht durch. `nginx.service` und `mariadb.service`
     * haben etwas erlaubt, das niemand benutzt: Der einzige Aufrufer von
     * `service.action` ist `Setup`, und der schickt eigene Units. Beide
     * Dienste werden über eigene, eng gefasste Operationen bedient.
     *
     * > **Eine Positivliste, die mehr erlaubt, als irgendwer benutzt,
     * > beschreibt eine Absicht und nicht den Gebrauch.**
     *
     * Dieser Fall hält die Kürzung fest: Was ein späterer Schritt braucht,
     * kommt mit Begründung dazu — und wird hier sichtbar, statt sich als
     * Nebenwirkung einzuschleichen.
     */
    public function test_the_allowlist_carries_only_what_is_used(): void
    {
        foreach (['nginx.service', 'mariadb.service', 'mysql.service', 'php8.3-fpm.service'] as $unit) {
            $this->assertFalse(
                ServiceAction::allows($unit),
                $unit.' ist wieder steuerbar — das ist eine Erweiterung der Sicherheitsgrenze.',
            );
        }

        // Und die Gegenrichtung: Ohne sie wäre eine leere Liste auch grün.
        $this->assertTrue(ServiceAction::allows('srvpanel-worker.service'));
        $this->assertTrue(ServiceAction::allows('srvpanel-agentd.service'));
    }

    /**
     * Nur die eigenen Units gelten als steuerbar.
     *
     * Der Katalog und die Positivliste stimmen darin überein — geprüft wird
     * das oben in beide Richtungen. Hier steht die Zahl, damit ein Umbau, der
     * eine fremde Unit still auf `true` setzt, auch dann auffällt, wenn er die
     * Positivliste gleich mitändert.
     */
    public function test_nothing_foreign_counts_as_controlled(): void
    {
        $fremdeGesteuert = array_values(array_filter(
            Catalog::all(),
            static fn (array $zeile): bool => ! $zeile['own'] && $zeile['controlled'],
        ));

        $this->assertSame(
            [],
            array_column($fremdeGesteuert, 'unit'),
            'Eine fremde Unit gilt als steuerbar — dann ist das eine Entscheidung, die jemand treffen muss.',
        );

        $eigeneGesteuert = array_filter(Catalog::all(), static fn (array $z): bool => $z['controlled']);
        $this->assertCount(12, $eigeneGesteuert, 'Die zwölf eigenen Units sind steuerbar — sonst prüft das nichts.');
    }
}
