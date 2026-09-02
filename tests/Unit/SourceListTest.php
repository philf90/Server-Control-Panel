<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Sources;

/**
 * Die beiden Sichten auf die Paketquellen — und der Verbund zwischen ihnen.
 *
 * ## Warum die Prüfkörper hier selbst gebaut werden
 *
 * Aus demselben Grund wie bei `InstLineTest`: Die Formen, an denen ein Leser
 * bricht, stehen auf der messenden Maschine gerade nicht da. Gemessen am
 * 26. August 2026 — dieser Container hat **keine** abgeschaltete Stanza, keine
 * auskommentierte `.list`-Zeile und keine Datei mit mehr als zwei Stanzas.
 *
 * > **Ein Prüfkörper aus dem Prüfling prüft den Prüfling gegen sich selbst.**
 *
 * ## Die sieben Fallen, alle gemessen
 *
 * 1. **`Sourcesentry` ist eine Stanza-Nummer, keine Zeilennummer.** In
 *    `ubuntu.sources` stehen die Stanzas auf Zeile 32 und 40 und heissen `:1`
 *    und `:2`. Wer dorthin springt, landet auf einem Kommentar.
 * 2. **Der Index verschiebt sich nicht, wenn eine Stanza abgeschaltet wird.**
 *    Abgeschaltete zählen mit — sonst bräche der Verbund genau in dem Fall,
 *    für den es ihn gibt.
 * 3. **Kommentarblöcke zählen nicht.** `ubuntu.sources` beginnt mit 31
 *    Kommentarzeilen, und die erste Feld-Stanza ist trotzdem `:1`.
 * 4. **Eine Fortsetzungszeile ist keine neue Zeile.** Ein PGP-Block reist
 *    gefaltet in einem Feld, mit `.` für die Leerzeile darin.
 * 5. **`Signed-By:` hat drei Formen** — ein Pfad, ein leerer Wert mit
 *    gefaltetem Block, und der Blockanfang **in derselben Zeile**. Alle drei
 *    standen an diesem Tag in einem einzigen `/etc/apt/sources.list.d`.
 * 6. **Im `.list`-Format gibt es kein `Enabled:`** — dort schaltet das
 *    Kommentarzeichen ab, und apt zählt nur, was es liest.
 * 7. **„Kein Ziel" heisst nicht „abgeschaltet".** Eine Quelle ohne geholten
 *    Index fehlt in `indextargets` genauso wie eine abgeschaltete.
 *
 * Die siebte ist die, die eine Anzeige falsch machen würde: Sie meldete
 * „abgeschaltet" für eine Quelle, die niemand abgeschaltet hat.
 */
final class SourceListTest extends TestCase
{
    /**
     * Ein `indextargets`-Block, wie apt ihn schreibt — gekürzt auf die Felder,
     * die dieser Leser nimmt, plus zwei, die er wegwerfen muss.
     */
    private const BLOCK = <<<'TXT'
        MetaKey: main/binary-amd64/Packages
        ShortDesc: Packages
        Codename: noble
        Label: Ubuntu
        Origin: Ubuntu
        Suite: noble-updates
        Trusted: yes
        Architecture: amd64
        Base-URI: http://archive.ubuntu.com/ubuntu/dists/noble-updates/
        Component: main
        Sourcesentry: /etc/apt/sources.list.d/ubuntu.sources:1
        Target-Of: deb
        TXT;

    /**
     * Die Prüfkörper für `Signed-By:`, jeder mit dem, was daraus werden muss.
     *
     * **Die Tabelle ist die Regel und nicht die Ausgabe.** Wer hier eine Form
     * herausnimmt, nimmt eine Falle heraus — `test_the_table_carries_every_form`
     * wird davon rot.
     *
     * @return array<string, array{0: string, 1: string, 2: null|string}>
     */
    public static function keys(): array
    {
        return [
            'ein Pfad' => [
                "Types: deb\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n",
                'path',
                '/usr/share/keyrings/ubuntu-archive-keyring.gpg',
            ],

            // Form 2: leerer Wert, der Block steht gefaltet darunter.
            'leer und gefaltet' => [
                "Types: deb\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n mQINBFl8fYEB\n -----END PGP PUBLIC KEY BLOCK-----\n",
                'embedded',
                null,
            ],

            // Form 3: der Blockanfang steht in derselben Zeile. Ein Leser, der
            // „nicht leer heisst Pfad" annimmt, meldet hier einen Dateinamen.
            'Blockanfang in derselben Zeile' => [
                "Types: deb\nSigned-By: -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n mQINBGYo0vEB\n -----END PGP PUBLIC KEY BLOCK-----\n",
                'embedded',
                null,
            ],

            'gar kein Feld' => [
                "Types: deb\nURIs: http://archive.ubuntu.com/ubuntu/\n",
                'none',
                null,
            ],
        ];
    }

    #[DataProvider('keys')]
    public function test_the_three_forms_of_signed_by_are_told_apart(string $stanza, string $art, ?string $pfad): void
    {
        $gelesen = Sources::stanzas($stanza);

        $this->assertCount(1, $gelesen, "Der Prüfkörper ergibt keine einzige Stanza:\n{$stanza}");

        $schluessel = Sources::key($gelesen[0]['fields']);

        $this->assertSame($art, $schluessel['kind'], "Falsch eingeordnet:\n{$stanza}");
        $this->assertSame($pfad, $schluessel['path'], "Falscher Pfad:\n{$stanza}");
    }

    /**
     * Die Untergrenze über die Formen.
     *
     * Ohne sie wäre das Herausnehmen einer Form eine stille Verkleinerung —
     * weniger Fälle, alle grün.
     */
    public function test_the_table_carries_every_form(): void
    {
        $arten = array_column(self::keys(), 1);

        foreach (['path', 'embedded', 'none'] as $art) {
            $this->assertContains($art, $arten, "In der Tabelle fehlt die Form '{$art}'.");
        }

        // Und die beiden eingebetteten Formen sind wirklich zwei: eine mit
        // leerem Wert, eine mit dem Blockanfang in derselben Zeile.
        $stanzas = array_column(self::keys(), 0);

        $this->assertNotSame([], array_values(array_filter(
            $stanzas,
            static fn (string $s): bool => preg_match('/^Signed-By:[ \t]*$/m', $s) === 1,
        )), 'Es fehlt die Form mit leerem Signed-By und gefaltetem Block.');

        $this->assertNotSame([], array_values(array_filter(
            $stanzas,
            static fn (string $s): bool => preg_match('/^Signed-By: -----BEGIN/m', $s) === 1,
        )), 'Es fehlt die Form mit dem Blockanfang in derselben Zeile.');
    }

    /**
     * `Sourcesentry` wird in Datei und **Stanza-Nummer** zerlegt.
     *
     * Die Zahl sieht aus wie eine Zeilennummer und ist keine. Getrennt wird am
     * **letzten** Doppelpunkt: Ein Dateiname darf welche enthalten.
     */
    public function test_the_source_entry_is_a_stanza_and_not_a_line(): void
    {
        $ziele = Sources::targets(self::BLOCK);

        $this->assertCount(1, $ziele);
        $this->assertSame('/etc/apt/sources.list.d/ubuntu.sources', $ziele[0]['file']);
        $this->assertSame(1, $ziele[0]['stanza']);

        // Ein Doppelpunkt im Dateinamen darf nicht die Trennstelle sein.
        $mit = str_replace('ubuntu.sources:1', 'a:b:2', self::BLOCK);
        $ziele = Sources::targets($mit);

        $this->assertSame('/etc/apt/sources.list.d/a:b', $ziele[0]['file']);
        $this->assertSame(2, $ziele[0]['stanza']);
    }

    /** Was keine Zahl ist, wird nicht zu Stanza 0. */
    public function test_an_entry_without_a_number_has_no_stanza(): void
    {
        foreach (['/etc/apt/sources.list.d/x.sources', '/etc/apt/x.sources:', '/etc/apt/x.sources:zwei'] as $wert) {
            $ziele = Sources::targets(str_replace('/etc/apt/sources.list.d/ubuntu.sources:1', $wert, self::BLOCK));

            $this->assertNull($ziele[0]['stanza'], "Aus '{$wert}' darf keine Stanza-Nummer werden.");
        }

        // Daneben eine, die eine ist — sonst misst die Aufzählung nichts.
        $this->assertSame(1, Sources::targets(self::BLOCK)[0]['stanza']);
    }

    /**
     * Nur die Felder, die auf eine Seite gehören.
     *
     * `indextargets` gibt 29 je Block aus. Wer alle durchreicht, schickt
     * Kompressionsverfahren und Zwischenspeicherpfade an eine Oberfläche, die
     * sie nicht anzeigt.
     */
    public function test_only_the_fields_that_belong_on_a_page_are_kept(): void
    {
        $felder = Sources::targets(self::BLOCK)[0]['fields'];

        $this->assertSame(['Origin', 'Label', 'Suite', 'Codename', 'Component', 'Architecture', 'Base-URI', 'Trusted'], Sources::FIELDS);
        $this->assertSame('noble-updates', $felder['Suite']);
        $this->assertSame('yes', $felder['Trusted']);

        $this->assertArrayNotHasKey('MetaKey', $felder);
        $this->assertArrayNotHasKey('Target-Of', $felder);
        $this->assertArrayNotHasKey('Sourcesentry', $felder, 'Die Herkunft steht neben den Feldern, nicht darin.');
    }

    /**
     * Kommentarblöcke zählen nicht, abgeschaltete Stanzas zählen mit.
     *
     * Beides zusammen, weil beides denselben Zähler betrifft — und weil genau
     * ihr Zusammenspiel den Verbund trägt.
     */
    public function test_comments_do_not_count_and_disabled_stanzas_do(): void
    {
        $datei = <<<'TXT'
            # Ein Kommentarblock ganz oben, durch eine Leerzeile abgetrennt.
            # Er darf keine Stanza sein.

            ## Und noch einer, direkt an der ersten Stanza.
            Types: deb
            URIs: http://archive.ubuntu.com/ubuntu/
            Suites: noble
            Enabled: no

            Types: deb
            URIs: http://security.ubuntu.com/ubuntu/
            Suites: noble-security
            TXT;

        $stanzas = Sources::stanzas($datei);

        $this->assertCount(2, $stanzas, 'Der Kommentarblock oben ist keine Stanza.');

        $this->assertSame(1, $stanzas[0]['stanza']);
        $this->assertFalse($stanzas[0]['enabled']);
        $this->assertSame('noble', $stanzas[0]['fields']['Suites']);

        // Der Kern: Die zweite behält ihre Nummer, obwohl die erste aus ist.
        $this->assertSame(2, $stanzas[1]['stanza'], 'Eine abgeschaltete Stanza zählt mit — sonst bricht der Verbund.');
        $this->assertTrue($stanzas[1]['enabled']);
    }

    /**
     * Ein gefalteter PGP-Block ist ein Feldwert und keine Folge von Zeilen.
     *
     * Ohne die Auflösung der Faltung liest ein Ausdruck mitten im Block weiter,
     * und eine Zeile daraus sieht aus wie ein Feld, sobald ein Doppelpunkt
     * darin vorkommt.
     */
    public function test_a_folded_key_block_does_not_become_fields(): void
    {
        $datei = <<<'TXT'
            Types: deb
            Signed-By:
             -----BEGIN PGP PUBLIC KEY BLOCK-----
             .
             Comment: das hier ist kein Feld
             mQINBFl8fYEBEADQmGZ6pDrwY9iH9DVlwNwTOvOZ7q7lHXPl
             -----END PGP PUBLIC KEY BLOCK-----
            Suites: noble
            TXT;

        $stanzas = Sources::stanzas($datei);

        $this->assertCount(1, $stanzas);
        $this->assertArrayNotHasKey('Comment', $stanzas[0]['fields'], 'Eine Zeile im gefalteten Block ist kein Feld.');
        $this->assertSame('noble', $stanzas[0]['fields']['Suites'], 'Nach dem Block geht es normal weiter.');
        $this->assertSame('embedded', Sources::key($stanzas[0]['fields'])['kind']);
    }

    /**
     * Der Einzeiler: Optionsklammer, URI, Suite, Komponenten.
     *
     * Eine auskommentierte Zeile ist **kein** Eintrag — im `.list`-Format gibt
     * es kein `Enabled:`, das Abschalten ist das Kommentarzeichen, und apt
     * zählt nur, was es liest.
     */
    public function test_a_commented_out_line_is_not_an_entry(): void
    {
        $datei = <<<'TXT'
            # ein Kommentar

            # deb https://download.docker.com/linux/ubuntu noble nightly
            deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable
            deb-src https://download.docker.com/linux/ubuntu noble test edge
            TXT;

        $eintraege = Sources::oneliners($datei);

        $this->assertCount(2, $eintraege, 'Die auskommentierte Zeile zählt nicht mit.');

        $this->assertSame(1, $eintraege[0]['stanza']);
        $this->assertSame('deb', $eintraege[0]['fields']['Types']);
        $this->assertSame('https://download.docker.com/linux/ubuntu', $eintraege[0]['fields']['URIs']);
        $this->assertSame('noble', $eintraege[0]['fields']['Suites']);
        $this->assertSame('stable', $eintraege[0]['fields']['Components']);
        $this->assertSame('/etc/apt/keyrings/docker.asc', Sources::key($eintraege[0]['fields'])['path']);

        // Der zweite: deb-src, keine Optionsklammer, zwei Komponenten.
        $this->assertSame(2, $eintraege[1]['stanza']);
        $this->assertSame('deb-src', $eintraege[1]['fields']['Types']);
        $this->assertSame('test edge', $eintraege[1]['fields']['Components']);
        $this->assertSame('none', Sources::key($eintraege[1]['fields'])['kind']);
    }

    /**
     * Ein fehlendes `Enabled:` heisst eingeschaltet — und geprüft wird gegen
     * `no` und nicht gegen `yes`.
     *
     * Sonst hielte ein Tippfehler die Quelle für abgeschaltet, und der
     * Betreiber suchte den Fehler dort, wo keiner ist.
     */
    public function test_a_missing_enabled_means_on_and_a_typo_does_not_switch_off(): void
    {
        $an = ['', 'Enabled: yes', 'Enabled: YES', 'Enabled: yess', 'Enabled: vielleicht'];

        foreach ($an as $zeile) {
            $stanzas = Sources::stanzas("Types: deb\nSuites: noble\n".$zeile."\n");

            $this->assertTrue($stanzas[0]['enabled'], "'{$zeile}' darf nicht abschalten.");
        }

        foreach (['Enabled: no', 'Enabled: NO', 'Enabled: false', 'Enabled: 0'] as $zeile) {
            $stanzas = Sources::stanzas("Types: deb\nSuites: noble\n".$zeile."\n");

            $this->assertFalse($stanzas[0]['enabled'], "'{$zeile}' muss abschalten.");
        }
    }

    /**
     * Die beiden Sichten beantworten verschiedene Fragen.
     *
     * **Das ist die Regel, um die es in diesem Schritt geht.** Aus den Zielen
     * allein sind zwei Zustände nicht zu unterscheiden: abgeschaltet, und
     * eingeschaltet ohne geholten Index. Hier stehen sie nebeneinander.
     */
    public function test_no_target_does_not_mean_switched_off(): void
    {
        $datei = <<<'TXT'
            Types: deb
            URIs: http://archive.ubuntu.com/ubuntu/
            Suites: noble
            Enabled: no

            Types: deb
            URIs: https://ppa.launchpadcontent.net/deadsnakes/ppa/ubuntu/
            Suites: noble

            Types: deb
            URIs: http://security.ubuntu.com/ubuntu/
            Suites: noble-security
            TXT;

        // apt kennt nur die dritte — die erste ist aus, die zweite unerreichbar.
        $ziele = Sources::targets(str_replace('ubuntu.sources:1', 'ubuntu.sources:3', self::BLOCK));

        $stanzas = Sources::stanzas($datei);
        $hatZiel = static fn (int $n): bool => $ziele[0]['stanza'] === $n;

        $this->assertFalse($stanzas[0]['enabled']);
        $this->assertFalse($hatZiel(1), 'Die abgeschaltete hat kein Ziel.');

        $this->assertTrue($stanzas[1]['enabled'], 'Die unerreichbare ist eingeschaltet …');
        $this->assertFalse($hatZiel(2), '… und hat trotzdem kein Ziel.');

        $this->assertTrue($stanzas[2]['enabled']);
        $this->assertTrue($hatZiel(3), 'Die dritte hat eines — sonst misst der Vergleich nichts.');
    }

    /**
     * Und die Adressen, die wirklich in Kraft sind.
     *
     * ## Der Befund, gegen den es diesen Fall gibt
     *
     * **Am Quelltext hergeleitet und nicht beobachtet** (`docs/96 §4b`,
     * Befund 14): apt holt eine abgeschaltete Quelle gar nicht erst, also
     * erzeugt sie keinen Fehlschlag — und die Simulation danach sieht mangels
     * neuer Listen nichts Anstehendes. Der Prüfkörper auf `cloudsrv24` hat den
     * Zustand nie hergestellt.
     *
     * > **Eine Quelle, die nicht gefragt wird, antwortet nicht falsch — sie
     * > fehlt, und das sieht aus wie Zustimmung.**
     *
     * ## Was hier gemessen wird
     *
     * Beide Richtungen: Eine eingeschaltete Stanza muss ihre Adresse liefern —
     * sonst bestünde ein `return []` diesen Fall zur Hälfte —, und eine
     * abgeschaltete darf es nicht. Dazu die drei Formen, an denen ein Leser
     * bricht: das fehlende Feld (apts Vorgabe ist **ein**), zwei Adressen in
     * einem Feld, und eine Datei, die es gar nicht gibt.
     */
    public function test_only_the_enabled_stanzas_name_an_address(): void
    {
        $datei = sys_get_temp_dir().'/srvpanel-sources-'.getmypid().'.sources';

        $schreibe = static function (string $inhalt) use ($datei): string {
            file_put_contents($datei, $inhalt);

            return $datei;
        };

        try {
            $this->assertSame(
                ['https://repo.example/apt'],
                Sources::enabledUris($schreibe('Types: deb
URIs: https://repo.example/apt
Suites: beta
')),
                'Ohne `Enabled:` ist eine Stanza eingeschaltet — apts Vorgabe.',
            );

            $this->assertSame(
                [],
                Sources::enabledUris($schreibe('Types: deb
URIs: https://repo.example/apt
Suites: beta
Enabled: no
')),
                'Eine abgeschaltete Quelle ist keine Adresse in Kraft — genau das war der Befund.',
            );

            $this->assertSame(
                ['https://zwei.example/apt'],
                Sources::enabledUris($schreibe(
                    'Types: deb
URIs: https://eins.example/apt
Suites: beta
Enabled: no
'
                    .'
'
                    .'Types: deb
URIs: https://zwei.example/apt
Suites: beta
'
                )),
                'Von zwei Stanzas zählt die eingeschaltete — und nur sie.',
            );

            $this->assertSame(
                ['https://a.example/apt', 'https://b.example/apt'],
                Sources::enabledUris($schreibe('Types: deb
URIs: https://a.example/apt https://b.example/apt
Suites: beta
')),
                'deb822 erlaubt mehrere Adressen in einem Feld; eine davon zu verlieren hiesse, eine tote Quelle nicht zu bemerken.',
            );

            $this->assertSame(
                [],
                Sources::enabledUris($schreibe('Types: deb
Suites: beta
')),
                'Eine Stanza ohne Adresse nennt keine.',
            );
        } finally {
            @unlink($datei);
        }

        $this->assertSame(
            [],
            Sources::enabledUris($datei),
            'Eine Datei, die es nicht gibt, nennt keine Adresse — und wirft nicht.',
        );
    }
}
