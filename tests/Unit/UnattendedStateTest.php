<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemPackagesUnattended;
use SrvPanel\Agent\Unattended;
use Tests\Support\WithoutPhpComments;

/**
 * Der Zustand der Automatik kommt von apt und nicht aus unserer Datei.
 *
 * ## Der gemessene Grund
 *
 * `docs/81 §7`, Falle 7 — und am 26. August 2026 live in diesem Container
 * angetroffen:
 *
 *     /etc/apt/apt.conf.d/20auto-upgrades:
 *         APT::Periodic::Update-Package-Lists "1";
 *         APT::Periodic::Unattended-Upgrade "1";
 *
 *     apt-config dump:
 *         APT::Periodic::Enable "0";        ← docker-disable-periodic-update
 *
 * Beide Teilschalter stehen auf „an", und die Automatik ist **aus**:
 * `/usr/lib/apt/apt.systemd.daily` steigt in Zeile 358 an `Enable` aus, bevor
 * es die anderen überhaupt liest.
 *
 * > **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen
 * > Zustand.**
 *
 * ## Und warum ein `99`-Präfix nicht genügt
 *
 * `/etc/apt/apt.conf.d` wird nach **ASCII** sortiert gelesen, die letzte
 * Zuweisung gewinnt — und Ziffern stehen vor Buchstaben. Gemessen mit drei
 * Prüfkörpern:
 *
 *     99-probe (Enable "7")  →  dump sagt "0"   (verloren)
 *     zz-probe (Enable "7")  →  dump sagt "7"   (gewonnen)
 *     ohne Prüfkörper        →  dump sagt "0"   (Gegenprobe)
 *
 * > **Ein Namensschema, das „zuletzt" bedeuten soll, bedeutet es nur, solange
 * > niemand einen Buchstaben davorschreibt.**
 *
 * Der Name ist deshalb ein Versuch und das Nachlesen die Zusage.
 */
final class UnattendedStateTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Eine Ausgabe von `apt-config dump`, wie sie hier gemessen wurde.
     *
     * **Selbstgebaut, Zeile für Zeile** — so wie `InstLineTest` seine
     * `Inst`-Zeilen baut. Eine Ausgabe von der Maschine, auf der gerade
     * gemessen wird, enthält genau die Fälle nicht, an denen der Leser bricht.
     */
    private const DUMP = <<<'TEXT'
    APT::Periodic "";
    APT::Periodic::Update-Package-Lists "1";
    APT::Periodic::Unattended-Upgrade "7";
    APT::Periodic::Enable "0";
    Unattended-Upgrade::Automatic-Reboot "false";
    Unattended-Upgrade::Allowed-Origins "";
    Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}";
    Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}-security";
    TEXT;

    public function test_the_dump_is_read_as_values_and_lists(): void
    {
        $gelesen = Unattended::read(self::DUMP);

        $this->assertSame('0', $gelesen['values'][Unattended::ENABLE]);
        $this->assertSame('7', $gelesen['values'][Unattended::UPGRADE]);

        /*
         * **Das doppelte `::` ist ein Listeneintrag und keine Zuweisung.** Wer
         * es als gewöhnliche Zeile läse, behielte nur den letzten Eintrag —
         * und die Seite meldete eine Herkunft, wo apt vier kennt.
         */
        $this->assertSame(
            ['${distro_id}:${distro_codename}', '${distro_id}:${distro_codename}-security'],
            $gelesen['lists'][Unattended::ORIGINS],
        );
    }

    /**
     * Eine fehlende Zeile heisst **an**.
     *
     * **Gemessen im Programm und nicht geraten:** `apt.systemd.daily` setzt
     * `AutoAptEnable=1  # default is yes` und überschreibt es erst mit dem,
     * was apt liefert. Wer aus dem Fehlen auf „aus" schlösse, meldete eine
     * abgeschaltete Automatik auf jedem frisch aufgesetzten Server.
     *
     * > **Eine Vorgabe, die nirgends steht, steht im Programm — und nur
     * > dort.**
     */
    public function test_a_missing_master_switch_means_on(): void
    {
        $this->assertTrue(Unattended::enabled([]));
        $this->assertTrue(Unattended::enabled([Unattended::ENABLE => '1']));
        $this->assertFalse(Unattended::enabled([Unattended::ENABLE => '0']));
    }

    /**
     * Der Abstand ist eine Zahl in Tagen und kein Wahrheitswert.
     *
     * `apt.systemd.daily` vergleicht ihn mit dem Alter des Zeitstempels; eine
     * `7` heisst „wöchentlich". Ein Leser, der `"7"` für `true` nähme, verlöre
     * genau die Auskunft, die den Unterschied macht.
     */
    public function test_an_interval_is_a_number_of_days(): void
    {
        $werte = Unattended::read(self::DUMP)['values'];

        $this->assertSame(7, Unattended::interval($werte, Unattended::UPGRADE));
        $this->assertSame(1, Unattended::interval($werte, Unattended::LISTS));
        $this->assertSame(0, Unattended::interval($werte, 'APT::Periodic::Gibtsnicht'));
    }

    /**
     * Die erklärende Liste nennt die Dateien in der Ordnung, in der apt liest.
     *
     * Der **letzte** Eintrag gewinnt — und weil ASCII sortiert wird, ist das
     * die Datei mit dem Buchstaben und nicht die mit der `99`.
     */
    public function test_the_setters_are_listed_in_the_order_apt_reads_them(): void
    {
        $dateien = [
            '/etc/apt/apt.conf.d/99-probe' => "APT::Periodic::Enable \"7\";\n",
            '/etc/apt/apt.conf.d/20auto-upgrades' => "APT::Periodic::Update-Package-Lists \"1\";\n",
            '/etc/apt/apt.conf.d/docker-disable-periodic-update' => "APT::Periodic::Enable \"0\";\n",
        ];

        $this->assertSame(
            [
                '/etc/apt/apt.conf.d/99-probe',
                '/etc/apt/apt.conf.d/docker-disable-periodic-update',
            ],
            Unattended::setters($dateien, Unattended::ENABLE),
            'Die Ordnung stimmt nicht mit der überein, in der apt liest — dann zeigt die Seite auf '
            .'die falsche Datei.',
        );
    }

    /**
     * Der Schalter schaltet das Auffrischen **nie** ab.
     *
     * `docs/81 §3`, Frage 4: Die Paketlisten aufzufrischen ändert nichts am
     * System und ist die Bedingung dafür, dass die Anzeige nicht lügt. Eine
     * Zahl, die drei Wochen alt ist, ist schlimmer als keine.
     */
    public function test_switching_off_keeps_the_lists_fresh(): void
    {
        $aus = Unattended::read(Unattended::fragment(false))['values'];
        $an = Unattended::read(Unattended::fragment(true))['values'];

        foreach (['aus' => $aus, 'an' => $an] as $lage => $werte) {
            $this->assertTrue(Unattended::enabled($werte), 'Der Hauptschalter fällt bei „'.$lage.'".');
            $this->assertGreaterThan(0, Unattended::interval($werte, Unattended::LISTS),
                'Das Auffrischen fällt bei „'.$lage.'".');
        }

        $this->assertSame(0, Unattended::interval($aus, Unattended::UPGRADE));
        $this->assertGreaterThan(0, Unattended::interval($an, Unattended::UPGRADE));
    }

    /**
     * Und von selbst neu gestartet wird nie.
     *
     * `docs/81 §3`: Ein Hosting-Server, der nachts um drei von selbst neu
     * startet, ist ein Ausfall mit guter Absicht.
     */
    public function test_the_fragment_never_allows_an_automatic_reboot(): void
    {
        foreach ([true, false] as $an) {
            $werte = Unattended::read(Unattended::fragment($an))['values'];

            $this->assertSame('false', $werte[Unattended::REBOOT] ?? '(fehlt)');
        }
    }

    /**
     * Und die Herkünfte setzt das Panel nicht.
     *
     * **Es betreibt die Automatik nicht, es konfiguriert die der
     * Distribution.** Deren Vorgabe ist breiter als `-security` allein
     * (gemessen: dazu die Release-Tasche und zwei ESM-Herkünfte); sie zu
     * verengen wäre eine Richtlinienentscheidung im Namen des Betreibers.
     */
    public function test_the_fragment_does_not_decide_the_origins(): void
    {
        foreach ([true, false] as $an) {
            $this->assertStringNotContainsString(
                Unattended::ORIGINS,
                Unattended::fragment($an),
                'Das Panel setzt die Herkünfte der Automatik. Das ist eine Richtlinie und keine '
                .'Einstellung — und sie stünde ohne einen Schalter da, der sie zurücknimmt.',
            );
        }
    }

    /**
     * Nach dem Schreiben wird nachgelesen — und zwar in dieser Reihenfolge.
     *
     * > **Erfolg wird gelesen, nicht geglaubt.**
     */
    public function test_the_switch_reads_back_after_writing(): void
    {
        $quelle = $this->source('agent/src/Ops/SystemPackagesUnattended.php');

        $schreiben = strpos($quelle, '$this->write(');
        $lesen = strpos($quelle, '$this->effective(');

        $this->assertIsInt($schreiben, 'Die Operation schreibt nichts mehr.');
        $this->assertIsInt($lesen, 'Die Operation liest nicht mehr nach.');
        $this->assertLessThan($lesen, $schreiben, 'Nachgelesen wird vor dem Schreiben — das misst den alten Zustand.');

        $this->assertStringContainsString("'apt-config'", $quelle,
            'Nachgelesen wird nicht über apt-config — dann ist es eine Frage an die eigene Datei.');
    }

    /**
     * Und der Wert kommt nirgends aus der eigenen Datei.
     *
     * Der Pfad darf vorkommen — geschrieben wird ja dorthin, und die Seite
     * zeigt ihn. Was nicht vorkommen darf, ist ein **Lesen** daraus.
     */
    public function test_no_value_is_read_from_our_own_file(): void
    {
        foreach ([
            'agent/src/Ops/SystemPackagesUnattended.php',
            'agent/src/Ops/SystemPackagesList.php',
        ] as $pfad) {
            $quelle = $this->source($pfad);

            /*
             * **Der Ausdruck grenzt ab, und der erste Wurf tat es nicht.**
             * `file(Unattended::FILE` traf auch `is_file(Unattended::FILE)` —
             * und das ist eine Frage nach dem Dasein und kein Lesen. Der
             * Wächter meldete damit die Zeile, die auf der Seite „vom Panel
             * verwaltet" anzeigt.
             *
             * > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und
             * > zwar von dem, der ihn gebaut hat.**
             */
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![_a-zA-Z])(?:file|file_get_contents|fopen|readfile)\(\s*Unattended::FILE/',
                $quelle,
                sprintf(
                    '%s liest den Zustand aus der eigenen Datei. Sie sagt nichts über den wirksamen '
                    .'Zustand — `apt-config dump` schon.',
                    $pfad,
                ),
            );
        }
    }

    /**
     * Der Weg zurück steht in der Paketierung.
     *
     * **Der Schalter entfernt die Datei nicht**, und zwar mit Absicht:
     * Ausgeschaltet hält sie weiterhin den Hauptschalter und das Auffrischen.
     * Damit bliebe sie beim `purge` liegen — eine Datei, die apt weiter liest,
     * während das Panel, das sie geschrieben hat, fort ist. Genau die Lücke
     * aus `docs/35`.
     */
    public function test_the_way_back_is_in_the_packaging(): void
    {
        $skript = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/scripts/postremove.sh');

        $this->assertStringContainsString(Unattended::FILE, $skript,
            'postremove.sh entfernt die Einstellung nicht mehr — dann bleibt sie beim purge liegen.');
        $this->assertStringContainsString('rm -f "${automatik}"', $skript);
    }

    /** Und die Operation heisst nach dem, was sie schaltet. */
    public function test_the_operation_is_registered_under_its_name(): void
    {
        $this->assertSame('system.packages.unattended', SystemPackagesUnattended::name());
        $this->assertTrue(SystemPackagesUnattended::mutating());
    }

    /**
     * Und der Ausdruck von oben findet, wogegen er geschrieben ist.
     *
     * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
     * > Null steht.**
     */
    public function test_the_pattern_tells_reading_from_asking(): void
    {
        $muster = '/(?<![_a-zA-Z])(?:file|file_get_contents|fopen|readfile)\(\s*Unattended::FILE/';

        foreach ([
            '$roh = file_get_contents(Unattended::FILE);',
            '$zeilen = file(Unattended::FILE);',
            '$h = fopen(Unattended::FILE, "r");',
        ] as $zeile) {
            $this->assertSame(1, preg_match($muster, $zeile), 'Nicht getroffen: '.$zeile);
        }

        foreach ([
            "'managed' => is_file(Unattended::FILE),",
            "'file' => Unattended::FILE,",
            '@unlink(Unattended::FILE);',
        ] as $zeile) {
            $this->assertSame(0, preg_match($muster, $zeile), 'Fälschlich getroffen: '.$zeile);
        }
    }

    private function source(string $relativ): string
    {
        return $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/'.$relativ));
    }

    /**
     * Aus einer Zahl von Tagen wird ein Satz und keine Mengenangabe.
     *
     * **Anlass ist „Listen alle 1 Tage"** aus dem Abnahmelauf zu A1 auf
     * `cloudsrv24` (`docs/86`, Befund 11) — in der einen Meldung, die ein
     * Betreiber liest, wenn seine Einstellung nicht wirkt.
     *
     * **Die Null ist der Fall, auf den es ankommt.** `apt.systemd.daily` liest
     * `0` als „gar nicht", und „alle 0 Tage" legt das Gegenteil nahe: Es klingt
     * nach ständig.
     *
     * > **Eine Zahl, die in ihrer Schreibweise das Gegenteil nahelegt, ist
     * > schlimmer als eine falsche Mehrzahl.**
     */
    public function test_a_rhythm_reads_as_a_sentence(): void
    {
        $this->assertSame('nie', Unattended::rhythm(0));
        $this->assertSame('täglich', Unattended::rhythm(1));
        $this->assertSame('alle 2 Tage', Unattended::rhythm(2));
        $this->assertSame('alle 7 Tage', Unattended::rhythm(7));

        // Ein negativer Wert kommt aus einer kaputten Datei und heisst nichts
        // anderes als null — „alle -1 Tage" wäre die schlechtere Auskunft.
        $this->assertSame('nie', Unattended::rhythm(-1));
    }
}
