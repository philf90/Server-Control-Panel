<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Keys;
use SrvPanel\Agent\Sources;
use Tests\Support\WithoutPhpComments;

/**
 * Fingerabdruck und Ablauf der Signaturschlüssel — `docs/81 §4` Punkt 4.
 *
 * ## Warum die Prüfkörper hier selbst gebaut werden
 *
 * Weil **kein Schlüsselbund dieser Maschine ein Ablaufdatum trägt**: gemessen
 * am 26. August 2026, 9 `pub`-Zeilen über acht Bunde, Feld 7 in allen neun
 * leer. Ein Prüfkörper aus dem laufenden System misst hier genau nichts.
 *
 * Die echte Zeile mit Ablauf stammt aus der CI — `tests/apt-faelle-messen.sh`
 * stellt sie auf allen vier Zielplattformen her, und der Lauf vom 26. August
 * meldet auf Debian 12 `Ablauf: 1819259803` neben `Ablauf: (leer)`.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * ## Die drei Fallen
 *
 * 1. **Feld 7 leer heisst „läuft nie ab"** und nicht „läuft am 1.1.1970 ab".
 * 2. **Die `fpr`-Zeile gehört zur zuletzt gesehenen `pub` ODER `sub`.** Hier
 *    stehen 12 `fpr` bei 11 `pub` und 1 `sub`; wer „die `fpr`-Zeile" nimmt,
 *    hängt einem Schlüssel den Fingerabdruck seines Unterschlüssels an.
 * 3. **Ein Fehlschlag ist kein leeres Ergebnis.** Ein Pfad, den es nicht gibt,
 *    endet mit `rc=2` — „keine Schlüssel gefunden" sähe aus wie „diese Quelle
 *    hat keinen", was etwas ganz anderes heisst.
 */
final class KeyExpiryTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Ein Bund mit Haupt- und Unterschlüssel, wie `gpg` ihn schreibt.
     *
     * Wortwörtlich die gemessene Ausgabe für `docker.asc` — gekürzt um die
     * `uid`-Zeile, die hier nichts trägt.
     */
    private const MIT_SUB = <<<'TXT'
        pub:-:4096:1:8D81803C0EBFCD88:1487788586:::-:::escaESCA::::::23::0:
        fpr:::::::::9DC858229FC7DD38854AE2D88D81803C0EBFCD88:
        uid:-::::1487792064::B50C6A3598EE2C27B34302761B93B277BF674C93::Docker Release (CE deb) \x3cdocker@docker.com\x3e::::::::::0:
        sub:-:4096:1:7EA0A9C3F273FCD8:1487788586::::::s::::::23:
        fpr:::::::::D3306A018370199E527AE7997EA0A9C3F273FCD8:
        TXT;

    /** Und einer mit Ablauf — die Zeile aus der CI, Debian 12. */
    private const MIT_ABLAUF = <<<'TXT'
        pub:-:3072:1:AAAABBBBCCCCDDDD:1755000000:1819259803:::-:::scESC::::::23::0:
        fpr:::::::::1111222233334444555566667777888899990000:
        uid:-::::1755000000::AAAA::Mit Ablauf \x3cbald@example.invalid\x3e::::::::::0:
        TXT;

    /**
     * Der Fingerabdruck des Hauptschlüssels und nicht der des Unterschlüssels.
     *
     * **Das ist die Falle, die man nur an einem Bund mit `sub` sieht** — und
     * genau die haben acht von neun Bunden dieser Maschine nicht.
     */
    public function test_the_fingerprint_belongs_to_the_key_and_not_to_its_subkey(): void
    {
        $gelesen = Keys::read(self::MIT_SUB);

        $this->assertCount(1, $gelesen, 'Ein Unterschlüssel ist kein eigener Eintrag.');
        $this->assertSame('9DC858229FC7DD38854AE2D88D81803C0EBFCD88', $gelesen[0]['fingerprint']);
        $this->assertNotSame(
            'D3306A018370199E527AE7997EA0A9C3F273FCD8',
            $gelesen[0]['fingerprint'],
            'Das ist der Fingerabdruck des Unterschlüssels.',
        );

        $this->assertSame('8D81803C0EBFCD88', $gelesen[0]['keyid']);
        $this->assertSame('Docker Release (CE deb) <docker@docker.com>', $gelesen[0]['uid']);
    }

    /** Ein leeres Feld 7 ist `null` und nicht `0`. */
    public function test_an_empty_expiry_is_never_and_not_the_epoch(): void
    {
        $ohne = Keys::read(self::MIT_SUB)[0];
        $mit = Keys::read(self::MIT_ABLAUF)[0];

        $this->assertNull($ohne['expires'], 'Leer heisst „läuft nie ab".');
        $this->assertSame(1819259803, $mit['expires'], 'Die gemessene Zeile aus der CI.');

        // Die Null daneben, damit die Aussage etwas bedeutet.
        $this->assertSame(1487788586, $ohne['created']);
    }

    /**
     * @return array<string, array{0: null|int, 1: string}>
     */
    public static function fristen(): array
    {
        // Alles relativ zu einem festen Jetzt — ein Test, der `time()` liest,
        // misst den Tag seines Laufs mit.
        $jetzt = 1756000000;

        return [
            'läuft nie ab' => [null, 'never'],
            'gestern abgelaufen' => [$jetzt - 86400, 'expired'],
            'genau jetzt' => [$jetzt, 'expired'],
            'in zehn Tagen' => [$jetzt + 10 * 86400, 'soon'],

            // Die Grenze, an beiden Seiten — sonst ist „dreissig Tage" eine
            // Zahl im Kommentar und keine im Code.
            'in 29 Tagen' => [$jetzt + 29 * 86400, 'soon'],
            'in 31 Tagen' => [$jetzt + 31 * 86400, 'ok'],
        ];
    }

    #[DataProvider('fristen')]
    public function test_the_thirty_day_boundary_holds(?int $expires, string $erwartet): void
    {
        $this->assertSame($erwartet, Keys::state($expires, 1756000000));
    }

    /** Und die Tabelle trägt beide Seiten der Grenze. */
    public function test_the_table_carries_both_sides_of_the_boundary(): void
    {
        $zustaende = array_column(self::fristen(), 1);

        foreach (['never', 'expired', 'soon', 'ok'] as $zustand) {
            $this->assertContains($zustand, $zustaende, "In der Tabelle fehlt der Zustand '{$zustand}'.");
        }

        $this->assertSame(
            Keys::SOON_SECONDS,
            30 * 86400,
            'Die Frist steht als Konstante und nicht als Zahl in einer Bedingung.',
        );
    }

    /**
     * Ein eingebetteter Block wird so aufgefaltet, dass `gpg` ihn liest.
     *
     * Nach deb822: Jede Fortsetzungszeile beginnt mit einem Leerzeichen, und
     * ein einzelner Punkt darin steht für die Leerzeile. Gemessen: So gefüttert
     * antwortet `gpg` mit rc 0 und einer `pub`-Zeile.
     */
    public function test_an_embedded_block_is_unfolded_for_gpg(): void
    {
        $stanza = <<<'TXT'
            Types: deb
            Signed-By:
             -----BEGIN PGP PUBLIC KEY BLOCK-----
             .
             mQINBFl8fYEBEADQmGZ6pDrwY9iH
             -----END PGP PUBLIC KEY BLOCK-----
            Suites: noble
            TXT;

        $felder = Sources::stanzas($stanza)[0]['fields'];
        $block = Sources::stanzas($stanza)[0]['block'];

        $aufgefaltet = Keys::unfold($felder['Signed-By'] ?? '', Sources::folded($block, 'Signed-By'));

        $this->assertStringStartsWith('-----BEGIN PGP PUBLIC KEY BLOCK-----', $aufgefaltet);
        $this->assertStringContainsString("\n\nmQINBFl8fYEBEADQmGZ6pDrwY9iH\n", $aufgefaltet);
        $this->assertStringEndsWith("-----END PGP PUBLIC KEY BLOCK-----\n", $aufgefaltet);

        // Kein führendes Leerzeichen mehr — sonst ist es kein PGP-Block.
        foreach (explode("\n", trim($aufgefaltet)) as $zeile) {
            $this->assertSame(ltrim($zeile), $zeile, "Diese Zeile trägt noch ihre Einrückung:\n{$zeile}");
        }
    }

    /**
     * Die dritte Form: der Blockanfang steht in derselben Zeile wie das Feld.
     *
     * Sie kommt auf dieser Maschine vor (`ondrej-ubuntu-php-noble.sources`) und
     * geht verloren, wenn `unfold()` nur die Faltung nimmt.
     */
    public function test_the_first_line_of_an_inline_block_is_not_lost(): void
    {
        $stanza = <<<'TXT'
            Types: deb
            Signed-By: -----BEGIN PGP PUBLIC KEY BLOCK-----
             .
             mQINBGYo0vEBEAC0Semxy5I2b8ex
             -----END PGP PUBLIC KEY BLOCK-----
            TXT;

        $eine = Sources::stanzas($stanza)[0];
        $aufgefaltet = Keys::unfold($eine['fields']['Signed-By'] ?? '', Sources::folded($eine['block'], 'Signed-By'));

        $this->assertStringStartsWith('-----BEGIN PGP PUBLIC KEY BLOCK-----', $aufgefaltet);
        $this->assertStringContainsString('mQINBGYo0vEBEAC0Semxy5I2b8ex', $aufgefaltet);
    }

    /** Die Faltung eines anderen Feldes gehört nicht dazu. */
    public function test_folding_of_another_field_is_not_taken(): void
    {
        $stanza = <<<'TXT'
            Types: deb
            Description: eine Beschreibung
             mit einer Fortsetzung
            Signed-By: /usr/share/keyrings/x.gpg
            Suites: noble
            TXT;

        $eine = Sources::stanzas($stanza)[0];

        $this->assertSame([], Sources::folded($eine['block'], 'Signed-By'));
        $this->assertNotSame([], Sources::folded($eine['block'], 'Description'), 'Sonst misst dieser Fall nichts.');
    }

    /**
     * Der Aufruf steht einmal da und trägt `--with-colons`.
     *
     * Ohne die Fahne schreibt `gpg` für Menschen, und jede Feldnummer in diesem
     * Leser zeigt ins Leere.
     */
    public function test_the_invocation_is_machine_readable_and_stated_once(): void
    {
        $this->assertContains('--with-colons', Keys::ARGUMENTS);
        $this->assertContains('--show-keys', Keys::ARGUMENTS);
        $this->assertContains('--batch', Keys::ARGUMENTS);

        /*
         * **Ohne den Abstreifer zählt dieser Wächter seinen eigenen
         * Gegenstand mit.** Der erste Wurf meldete drei statt zwei Stellen —
         * die dritte stand im Dokumentblock über der Methode.
         *
         *   Ein Wächter, der eine Zeichenkette sucht, findet sie auch in dem
         *   Satz, der sie erklärt.
         */
        $quelle = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/SystemSourcesList.php'),
        );

        $this->assertSame(
            0,
            substr_count($quelle, "'--with-colons'"),
            'Der Aufruf gehört als Keys::ARGUMENTS an eine Stelle und nicht ausgeschrieben in die Operation.',
        );
        $this->assertSame(2, substr_count($quelle, 'Keys::ARGUMENTS'), 'Beide Wege — Pfad und stdin — benutzen ihn.');
    }

    /**
     * `gpg` steht mit absolutem Pfad auf der Positivliste.
     *
     * Die erste Grenze dieses Projekts: Der Agent startet nur, was dort steht.
     * Ein Aufruf ohne Eintrag scheitert zur Laufzeit — und zwar erst auf dem
     * Server.
     */
    public function test_gpg_is_on_the_allowlist_with_an_absolute_path(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Runner.php');

        $this->assertMatchesRegularExpression(
            "/'gpg' => '\/[^']+\/gpg',/",
            $quelle,
            'gpg fehlt in Runner::PROGRAMS oder steht dort ohne absoluten Pfad.',
        );
    }
}
