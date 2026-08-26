<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Packages;

/**
 * Der Leser trennt `[alt]` von `[arch]` und liest **alle** Herkünfte.
 *
 * ## Warum die Prüfkörper hier selbst gebaut werden
 *
 * Weil `apt-get -s` auf der Maschine, auf der gerade gemessen wird, genau die
 * Fälle **nicht** enthält, an denen der Leser bricht. Gemessen am 26. August
 * 2026: Auf `debian:12` gibt es keine einzige `Inst`-Zeile, weil das Abbild
 * vollständig aktuell ist; auf diesem Container gibt es 145, aber keine
 * einzige Neuinstallation und keine einzige Entfernung. Ein Prüfkörper aus dem
 * laufenden System prüft also, was hier zufällig ansteht — und nicht die Form.
 *
 * Dasselbe Vorbild wie `ArchiveDepthTest`, der seine Archive Byte für Byte
 * selbst baut, statt eines aus dem Prüfling zu nehmen.
 *
 * > **Ein Prüfkörper aus dem Prüfling prüft den Prüfling gegen sich selbst.**
 *
 * ## Die vier Fallen der `Inst`-Zeile
 *
 * Alle vier sind an echter apt-Ausgabe gemessen und keine erfunden:
 *
 * 1. **`[alt]` fehlt.** Bei einer Neuinstallation steht nach dem Namen nichts.
 *    Wer „die eckige Klammer" nimmt, greift dann die **Architektur**.
 * 2. **Die Architektur steht auch in eckigen Klammern** — nur am Ende und
 *    innerhalb der runden. Beide Formen kommen nebeneinander vor.
 * 3. **Es sind mehrere Herkünfte**, durch Komma getrennt, und die
 *    Sicherheitssuite ist die **zweite**. Wer die erste nimmt, zählt jedes
 *    Sicherheitsupdate als gewöhnliches.
 * 4. **Hinter der schliessenden runden Klammer steht noch etwas** — was diese
 *    Zeile ausgelöst hat, als eine oder mehrere eckige Gruppen, manchmal leer.
 *    Ein `$` davor wirft jede solche Zeile wortlos weg: gemessen 145 Zeilen,
 *    davon 56 mit Anhang, gelesen wurden 89.
 *
 * Die vierte ist die teuerste gewesen, weil sie **wie ein Ergebnis aussah**:
 * Die Operation meldete 89 aktualisierbare Pakete, die Kommandozeile 145, und
 * 89 ist eine Zahl, die niemand für einen Fehler hält.
 *
 * > **Eine Zeile, die der Leser verwirft, fehlt in keiner Summe — sie fehlt
 * > nur im Ergebnis.**
 */
final class InstLineTest extends TestCase
{
    /**
     * Die Prüfkörper, jeder mit dem, was der Leser aus ihm machen muss.
     *
     * **Die Tabelle ist die Regel und nicht die Ausgabe.** Wer hier eine Zeile
     * herausnimmt, nimmt eine Falle heraus — und
     * `test_the_table_carries_every_trap` wird davon rot. Ohne diese
     * Untergrenze wäre das Entfernen der Zeile ohne `[alt]` eine stille
     * Verkleinerung: weniger Fälle, alle grün.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function lines(): array
    {
        return [
            'gewöhnlich' => [
                'Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])',
                [
                    'name' => 'coreutils',
                    'old' => '9.4-3ubuntu6.1',
                    'new' => '9.4-3ubuntu6.2',
                    'origins' => ['Ubuntu:24.04/noble-updates'],
                    'architecture' => 'amd64',
                    'security' => false,
                ],
            ],

            // Falle 1 und 2 zugleich: keine alte Fassung, und die einzige
            // eckige Klammer der Zeile ist die Architektur.
            'ohne alte Fassung' => [
                'Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])',
                [
                    'name' => 'cowsay',
                    'old' => null,
                    'new' => '3.03+dfsg2-8',
                    'origins' => ['Ubuntu:24.04/noble'],
                    'architecture' => 'all',
                    'security' => false,
                ],
            ],

            // Falle 3: zwei Herkünfte, und die Sicherheitssuite ist die zweite.
            'zwei Herkünfte' => [
                'Inst libc6 [2.39-0ubuntu8.6] (2.39-0ubuntu8.7 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])',
                [
                    'name' => 'libc6',
                    'old' => '2.39-0ubuntu8.6',
                    'new' => '2.39-0ubuntu8.7',
                    'origins' => ['Ubuntu:24.04/noble-updates', 'Ubuntu:24.04/noble-security'],
                    'architecture' => 'amd64',
                    'security' => true,
                ],
            ],

            // Falle 4 in ihrer leeren Form — die häufigste: 50 von 145.
            'leerer Anhang' => [
                'Inst perl [5.38.2-3.2ubuntu0.2] (5.38.2-3.2ubuntu0.3 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64]) []',
                [
                    'name' => 'perl',
                    'old' => '5.38.2-3.2ubuntu0.2',
                    'new' => '5.38.2-3.2ubuntu0.3',
                    'origins' => ['Ubuntu:24.04/noble-updates', 'Ubuntu:24.04/noble-security'],
                    'architecture' => 'amd64',
                    'security' => true,
                ],
            ],

            // Falle 4 gefüllt. Das Leerzeichen vor der schliessenden Klammer
            // schreibt apt selbst; es ist kein Tippfehler.
            'benannter Anhang' => [
                'Inst libperl5.38t64 [5.38.2-3.2ubuntu0.2] (5.38.2-3.2ubuntu0.3 Ubuntu:24.04/noble-updates [amd64]) [perl:amd64 ]',
                [
                    'name' => 'libperl5.38t64',
                    'old' => '5.38.2-3.2ubuntu0.2',
                    'new' => '5.38.2-3.2ubuntu0.3',
                    'origins' => ['Ubuntu:24.04/noble-updates'],
                    'architecture' => 'amd64',
                    'security' => false,
                ],
            ],

            // Falle 4 zweimal an einer Zeile — gemessen, nicht ausgedacht.
            'zwei Anhänge' => [
                'Inst libpam-modules-bin [1.5.3-5ubuntu5.5] (1.5.3-5ubuntu5.6 Ubuntu:24.04/noble-security [amd64]) [libpam-modules:amd64 on libpam-modules-bin:amd64] [libpam-modules:amd64 ]',
                [
                    'name' => 'libpam-modules-bin',
                    'old' => '1.5.3-5ubuntu5.5',
                    'new' => '1.5.3-5ubuntu5.6',
                    'origins' => ['Ubuntu:24.04/noble-security'],
                    'architecture' => 'amd64',
                    'security' => true,
                ],
            ],

            // Ohne Architektur — ältere apt-Fassungen lassen sie weg, und ein
            // Paket aus einer lokalen Datei hat ausserdem keine Herkunft.
            'ohne Architektur' => [
                'Inst sl [3.03+dfsg2-8] (3.03+dfsg2-9 Debian:13/stable)',
                [
                    'name' => 'sl',
                    'old' => '3.03+dfsg2-8',
                    'new' => '3.03+dfsg2-9',
                    'origins' => ['Debian:13/stable'],
                    'architecture' => null,
                    'security' => false,
                ],
            ],

            // Debian nennt den Anbieter `Debian-Security` — und die Suite
            // heisst hier ebenfalls so. Die Gegenprobe darunter trennt die
            // beiden.
            'Debian-Security' => [
                'Inst libssl3 [3.0.15-1] (3.0.16-1 Debian-Security:13/stable-security [amd64])',
                [
                    'name' => 'libssl3',
                    'old' => '3.0.15-1',
                    'new' => '3.0.16-1',
                    'origins' => ['Debian-Security:13/stable-security'],
                    'architecture' => 'amd64',
                    'security' => true,
                ],
            ],

            // Ein Anbieter mit **Leerzeichen** und ohne Schrägstrich —
            // gemessen und nicht ausgedacht: Von den drei Herkunftsformen
            // dieses Containers ist das die dritte. Wer die Herkünfte am
            // Leerzeichen trennt statt am Komma, macht daraus zwei.
            'Anbieter mit Leerzeichen' => [
                'Inst docker-ce-cli [5:29.3.1-1~ubuntu.24.04~noble] (5:29.7.2-1~ubuntu.24.04~noble Docker CE:noble [amd64])',
                [
                    'name' => 'docker-ce-cli',
                    'old' => '5:29.3.1-1~ubuntu.24.04~noble',
                    'new' => '5:29.7.2-1~ubuntu.24.04~noble',
                    'origins' => ['Docker CE:noble'],
                    'architecture' => 'amd64',
                    'security' => false,
                ],
            ],

            // Die Gegenprobe dazu: ein Anbieter, der auf `-security` endet,
            // und eine Suite, die es nicht tut. Ein `str_contains` fiele hier
            // herein.
            'Anbieter heisst security' => [
                'Inst eigenes [1.0] (1.1 foo-security:1/stable [amd64])',
                [
                    'name' => 'eigenes',
                    'old' => '1.0',
                    'new' => '1.1',
                    'origins' => ['foo-security:1/stable'],
                    'architecture' => 'amd64',
                    'security' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $erwartet
     */
    #[DataProvider('lines')]
    public function test_the_reader_separates_the_old_version_from_the_architecture(string $zeile, array $erwartet): void
    {
        $gelesen = Packages::inst($zeile);

        $this->assertIsArray($gelesen, "Die Zeile wurde gar nicht gelesen:\n{$zeile}");
        $this->assertSame($erwartet, $gelesen, "Falsch gelesen:\n{$zeile}");
    }

    /**
     * Die Untergrenze: Jede der vier Fallen steht mindestens einmal in der
     * Tabelle.
     *
     * **Das ist der Test, den der Bruch rot macht.** Ohne ihn wäre das
     * Herausnehmen der Zeile ohne `[alt]` eine stille Verkleinerung: Die
     * übrigen acht Fälle bleiben grün, und der Wächter meldet nichts.
     *
     * > **Ein Wächter über eine Aufzählung, der keine Untergrenze hat, wird
     * > beim Kürzen nicht rot — er wird kürzer.**
     */
    public function test_the_table_carries_every_trap(): void
    {
        $zeilen = array_column(self::lines(), 0);

        $fallen = [
            'eine Zeile ohne alte Fassung' => static fn (string $z): bool => preg_match('/^Inst [^\s\[]+ \(/', $z) === 1,
            'eine Zeile mit mehreren Herkünften' => static fn (string $z): bool => str_contains($z, ', '),
            'eine Zeile ohne Architektur' => static fn (string $z): bool => preg_match('/\[[a-z0-9]+\]\)/', $z) !== 1,
            'eine Zeile mit leerem Anhang' => static fn (string $z): bool => str_ends_with($z, ') []'),
            'eine Zeile mit benanntem Anhang' => static fn (string $z): bool => preg_match('/\) \[[^\]]+\]/', $z) === 1,
            'eine Zeile mit zwei Anhängen' => static fn (string $z): bool => preg_match('/\) \[[^\]]*\] \[[^\]]*\]$/', $z) === 1,
            'eine Zeile mit Leerzeichen in der Herkunft' => static fn (string $z): bool => preg_match('/\(\S+ [A-Za-z]+ [A-Za-z]+:/', $z) === 1,
        ];

        foreach ($fallen as $was => $trifft) {
            $this->assertNotSame(
                [],
                array_values(array_filter($zeilen, $trifft)),
                "In der Tabelle fehlt {$was}. Wer sie herausnimmt, nimmt eine Falle heraus."
            );
        }
    }

    /**
     * Eine fehlende alte Fassung ist `null` und nicht `''`.
     *
     * Die Unterscheidung kostet nichts und trägt `fresh`: Ein Betreiber, der
     * „12 Aktualisierungen" liest und hinterher drei neue Pakete auf der Platte
     * hat, ist belogen worden.
     */
    public function test_a_missing_old_version_is_null_and_not_empty(): void
    {
        $neu = Packages::inst('Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])');
        $alt = Packages::inst('Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble [amd64])');

        $this->assertIsArray($neu);
        $this->assertIsArray($alt);

        $this->assertNull($neu['old'], 'Eine Neuinstallation hat keine alte Fassung.');
        $this->assertSame('9.4-3ubuntu6.1', $alt['old']);

        // Und die Zahl daneben, damit die Null etwas bedeutet.
        $gelesen = Packages::read(
            "Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])\n"
            ."Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble [amd64])\n",
            ''
        );

        $this->assertSame(2, count($gelesen['upgradable']));
        $this->assertSame(1, $gelesen['fresh'], 'Genau eine der beiden Zeilen ist eine Neuinstallation.');
    }

    /**
     * Alle Herkünfte, nicht die erste.
     *
     * apt nennt die Aktualisierungssuite zuerst und die Sicherheitssuite
     * danach. Wer die erste nimmt, zählt **jedes** Sicherheitsupdate als
     * gewöhnliches — gemessen auf diesem Container: 124 von 145.
     */
    public function test_every_origin_is_read_and_not_only_the_first(): void
    {
        $gelesen = Packages::inst(
            'Inst libc6 [2.39-0ubuntu8.6] (2.39-0ubuntu8.7 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])'
        );

        $this->assertIsArray($gelesen);
        $this->assertCount(2, $gelesen['origins'], 'Beide Herkünfte gehören gelesen.');
        $this->assertNotSame(
            'noble-security',
            substr((string) ($gelesen['origins'][0] ?? ''), -15),
            'Die Sicherheitssuite steht hier absichtlich an zweiter Stelle — sonst prüft der Fall nichts.'
        );
        $this->assertTrue($gelesen['security'], 'Irgendeine der Herkünfte genügt, nicht nur die erste.');
    }

    /**
     * Geprüft wird am **Ende** der Herkunft und nicht irgendwo darin.
     *
     * Der Anbieter steht vorn. Ein `str_contains` hielte `foo-security:1/stable`
     * für ein Sicherheitsupdate — ein Anbieter, der so heisst, sagt über die
     * Suite nichts.
     *
     * **Der Fall mit dem Anbieter ist der tragende.** Ohne ihn wäre jede
     * Fassung grün, die irgendwo nach dem Wort sucht; die übrigen vier Zeilen
     * unterscheiden `str_contains` und `str_ends_with` nicht.
     */
    public function test_the_end_of_the_origin_decides_and_not_the_vendor(): void
    {
        $this->assertTrue(Packages::security(['Ubuntu:24.04/noble-security']));
        $this->assertTrue(Packages::security(['Debian-Security:13/stable-security']));
        $this->assertTrue(Packages::security(['Ubuntu:24.04/noble-updates', 'Ubuntu:24.04/noble-security']));

        $this->assertFalse(
            Packages::security(['foo-security:1/stable']),
            'Ein Anbieter, der auf -security endet, macht aus stable keine Sicherheitssuite.'
        );
        $this->assertFalse(Packages::security(['Ubuntu:24.04/noble-updates']));

        // Ein Anbieter mit Leerzeichen und ohne Schrägstrich — gemessen, nicht
        // ausgedacht: `Docker CE:noble` steht so in der Ausgabe dieses
        // Containers.
        $this->assertFalse(Packages::security(['Docker CE:noble']));

        $this->assertFalse(Packages::security([]));
    }

    /**
     * Der Anhang wird geduldet und **nicht** gelesen.
     *
     * Er beantwortet „warum wird das aktualisiert", und danach fragt keine
     * Anzeige dieser Stufe. Entstünde daraus ein Feld, das niemand liest, wäre
     * es von aussen nicht von einem zu unterscheiden, das es nicht gibt.
     *
     * Geprüft wird beides: dass er die Zeile nicht mehr verwirft, **und** dass
     * er sich nirgends im Ergebnis niederschlägt.
     */
    public function test_the_trailing_group_is_tolerated_and_not_read(): void
    {
        $ohne = Packages::inst('Inst perl [5.38.2-3.2ubuntu0.2] (5.38.2-3.2ubuntu0.3 Ubuntu:24.04/noble-updates [amd64])');
        $mit = Packages::inst('Inst perl [5.38.2-3.2ubuntu0.2] (5.38.2-3.2ubuntu0.3 Ubuntu:24.04/noble-updates [amd64]) [libperl5.38t64:amd64 ]');

        $this->assertIsArray($mit, 'Eine Zeile mit Anhang wird gelesen und nicht verworfen.');
        $this->assertSame($ohne, $mit, 'Der Anhang darf am Ergebnis nichts ändern.');

        // Und die Gegenprobe: Er darf nicht in der Architektur landen.
        $this->assertSame('amd64', $mit['architecture']);
        $this->assertSame(['Ubuntu:24.04/noble-updates'], $mit['origins']);
    }

    /**
     * Was keine `Inst`-Zeile ist, ist keine.
     *
     * Die eingerückten Namen unter „The following packages will be upgraded:"
     * stehen im selben Text und dürfen nicht mitgezählt werden — ebensowenig
     * die `Conf`-Zeilen, von denen es genauso viele gibt wie `Inst`-Zeilen.
     */
    public function test_what_is_not_an_inst_line_is_none(): void
    {
        $keine = [
            'Conf coreutils (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])',
            'Remv sl [3.03+dfsg2-8]',
            '  ca-certificates containerd.io curl distro-info-data',
            'The following packages will be upgraded:',
            'Reading package lists...',
            '',
            'Instandsetzung',
        ];

        foreach ($keine as $zeile) {
            $this->assertNull(Packages::inst($zeile), "Das ist keine Inst-Zeile:\n{$zeile}");
        }

        // Daneben eine, die es ist — sonst misst die Aufzählung nichts.
        $this->assertIsArray(Packages::inst('Inst sl [1.0] (1.1 Debian:13/stable [amd64])'));
    }

    /**
     * `Remv` liefert den Namen, `Inst` nicht.
     *
     * Beide fangen mit vier Buchstaben und einem Leerzeichen an; ein Leser, der
     * nur auf den Namen sieht, verwechselt sie.
     */
    public function test_a_removal_is_read_as_one(): void
    {
        $this->assertSame('software-properties-common', Packages::remv('Remv software-properties-common [0.99.49.4]'));
        $this->assertSame('packagekit', Packages::remv('Remv packagekit [1.2.8-2ubuntu1.4]'));

        $this->assertNull(Packages::remv('Inst sl [1.0] (1.1 Debian:13/stable [amd64])'));
        $this->assertNull(Packages::remv('Conf sl (1.1 Debian:13/stable [amd64])'));
    }

    /**
     * Die zurückgehaltenen Pakete stehen eingerückt unter ihrer Überschrift.
     *
     * Die Liste endet an der ersten Zeile, die nicht eingerückt ist — dort
     * beginnt der nächste Abschnitt. Wer das übersieht, zählt die
     * aktualisierbaren Pakete als zurückgehalten mit.
     */
    public function test_kept_back_ends_at_the_next_section(): void
    {
        $upgrade = <<<'TXT'
            Reading package lists...
            Building dependency tree...
            The following packages have been kept back:
              tar dpkg
              libc6
            The following packages will be upgraded:
              curl wget
            3 upgraded, 0 newly installed, 0 to remove.
            TXT;

        $this->assertSame(['tar', 'dpkg', 'libc6'], Packages::keptBack($upgrade));
    }

    /** Ohne die Überschrift ist nichts zurückgehalten — und nicht alles. */
    public function test_without_the_heading_nothing_is_kept_back(): void
    {
        $upgrade = <<<'TXT'
            The following packages will be upgraded:
              curl wget
            2 upgraded, 0 newly installed, 0 to remove.
            TXT;

        $this->assertSame([], Packages::keptBack($upgrade));
    }
}
