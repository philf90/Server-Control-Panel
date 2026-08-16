<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Ssh\PublicKey;

/**
 * Was in `authorized_keys` kommt, ist ein Schlüssel — und sonst nichts.
 *
 * ## Die Prüfdaten sind gemessen und nicht ausgedacht
 *
 * Die Schlüssel unten sind am 16. August 2026 mit `ssh-keygen` erzeugt worden,
 * und die Fingerabdrücke daneben sind die, die `ssh-keygen -lf` dazu ausgibt.
 * Sie stehen hier als Zeichenketten, damit dieser Wächter kein Programm
 * braucht — aber es sind keine erfundenen Werte.
 *
 * > **Ein Testdatensatz, den sich jemand ausdenkt, prüft, was er sich gedacht
 * > hat.** `docs/48` hat das an einem `ü` erlebt, das als deutsches Wort
 * > dastand und nicht als Prüfung.
 *
 * ## Und der Fall, der nicht auffällt
 *
 * `hash('sha256', '')` ist ein gültig aussehender Fingerabdruck über nichts.
 * Ein Parser, der bei kaputter Eingabe still weitermacht, zeigt ihn im Panel an
 * — und der Kunde vergleicht ihn mit dem seinen und findet den Fehler nicht.
 * {@see self::test_a_broken_key_never_yields_a_fingerprint()} steht deshalb
 * neben den drei richtigen.
 *
 * **Die Brüche dazu** (`tests/waechter-brechen.sh`): die Typprüfung fallen
 * lassen, sodass eine Zeile mit `command=` durchgeht; die Steuerzeichen
 * durchlassen; die RSA-Untergrenze streichen.
 */
final class PublicKeyTest extends TestCase
{
    /** Ein ed25519, wie ssh-keygen ihn schreibt. */
    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICyquD1b7we1RqBlWg/mJ/K6aa9SYD8/c+v7zvo/EOUv kunde@beispiel.de';

    private const ED25519_FINGERPRINT = 'SHA256:BBOKMrAayIwglg/j2zFqtndMLZAVvlYDQlbXrwNewEM';

    private const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBJ42ih4FMnrHyPLv3qOfRDtx2C/5k2YYyKbPz45f2D832Y86iqeyP/FqlAa3qwYuQwpMIcRENMZfrVvRqcPfVLY= ecdsa@beispiel.de';

    private const ECDSA_FINGERPRINT = 'SHA256:l/0RevX1GEJBTW3xzxa3dZjw6fd30sCMOB8sBivwJzY';

    private const RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQC6LjZc//Wf+b3JqEtGV6+F/jD+gqsbLnNWyW+ByBb/L4rlTjIpUEq3kWVceX589CBWgGysA9yyUWRZbLeoeo17gTlP/+3VGbmgexqHskG93d/a0SZu9gxXLu+pYmFprdkfL7MVMF4VYx1ZsW3hhPbw8ofZqMR10an/L+mGLzo93yXHF/4ngKwRCEmjA0UuZzwkwmu2LlqTpzUxEFesgaP/F35Qy+P8ujNvlgTwu1NINmKH39q3hGndXaPfKNdblw9QcT9sXCu1vyyLQ73NvBgT7e35O26gSCDTtSNYmsaNcFIraEtfk/aLeQAOEZVlN8YDKMN8AXEgaCG0zl+hF/YKectJxt3wzUZBe1elbhJnge6TSkuorDiGrxWLY8htdtcmG/0Ddh2JGZSeIA7qAkq4iiluD5P2sIZ0MnfMnyr99pSmzmf157iGFKIe3sxmt8XYI5+6eN6Nbhsaa/vN8eF43rINzsl2h/pVP1XTrLCONCfiL72Z3M9JApQQro5EH9U= rsa@beispiel.de';

    private const RSA_FINGERPRINT = 'SHA256:5lmj1t2Wvnb8AbIBEVtW5H9PXWZ5027idxS3DdZIvrM';

    /** Derselbe Griff, nur mit 1024 Bit — `ssh-keygen` legt ihn anstandslos an. */
    private const RSA_SHORT = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDqSwZtdz/EpBiHxgTbr4iKxniRWhN+/Toi8khGtvZV+drRHW/n/dJ1SNqzFMwmIUgb3Yw12hz/Y/BmBMGUVOqFDUhwl9Tba9sh5viPfxIX81nb1mOvpGBZWaoPWBgdmFrwzEIvzJH+T4064JT9vuo6qCO8U9l3Gp3ojTNOwENtcw== zu-kurz@beispiel.de';

    private const DSA = 'ssh-dss AAAAB3NzaC1kc3MAAACBAKYMqRmBByEKs8H6RUfdCjpveRqIrf4WmuLgn6oVw5b0ievM+w7SS46InRtiGVOU/zB9Fc75zfrygpNWV670MV7PCQ1m/mhX2KWzM3+NMQodMiquo2qZ82Wt/eLKqhmm/wFbYEdRlsEg/3Z2i5/f1jJOz2rbKk7Hs5o2Ejuarq0tAAAAFQCN39YHoAfcqFyOfqgrcCvcV1bMjwAAAIBZfSYlPHdvtf0xGp35w3eM0GX0banYB45Ioj0hKvx7MkC3QIBDugobc5sMKYkPRgEd1e4wpAWLimCicH8OLZ54qXDh4SDtIXbus0pDr4WaBjYSl0FBq92N4kgRqpvI/CmpGo1QIuoxgJ45k3pamjsCgvLP+0m0gKEBiI0gEFtGKQAAAIBpBQuNqdA30hjeCp2hMuuDwXdACd/32EU777+CNd8iXPL8UdkiKpM1JWgvpdnpdyDth2l1GMr2vbSUmkHOKUISIXSgYMfzzUHBrgtZAdRWNEQcmp98KGGZ/I1nTko6BdITHjuicyb6K3Lk0OiAW7C0IiRulgwX75gnhFav6G7EmA== dsa@beispiel.de';

    // ------------------------------------------------------------- was gilt

    /** Der Fingerabdruck ist der, den der Kunde vor sich sieht. */
    public function test_the_fingerprint_is_the_one_ssh_keygen_prints(): void
    {
        $this->assertSame(self::ED25519_FINGERPRINT, PublicKey::parse(self::ED25519)['fingerprint']);
        $this->assertSame(self::ECDSA_FINGERPRINT, PublicKey::parse(self::ECDSA)['fingerprint']);
        $this->assertSame(self::RSA_FINGERPRINT, PublicKey::parse(self::RSA)['fingerprint']);
    }

    /** Und die Länge ebenfalls — bei RSA aus dem Modul gerechnet. */
    public function test_the_length_is_the_one_ssh_keygen_prints(): void
    {
        $this->assertSame(256, PublicKey::parse(self::ED25519)['bits']);
        $this->assertSame(256, PublicKey::parse(self::ECDSA)['bits']);
        $this->assertSame(3072, PublicKey::parse(self::RSA)['bits'], 'Das führende Nullbyte ist mitgezählt worden.');
    }

    /** Ein Kommentar mit Leerzeichen bleibt einer und wird nicht zu zwei Feldern. */
    public function test_a_comment_with_spaces_survives(): void
    {
        $key = PublicKey::parse('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICyquD1b7we1RqBlWg/mJ/K6aa9SYD8/c+v7zvo/EOUv Anna ihr Laptop');

        $this->assertSame('Anna ihr Laptop', $key['comment']);
    }

    // ------------------------------------------------------ was nicht gilt

    /**
     * Eine Zeile mit Optionen davor kommt nicht herein.
     *
     * Gemessen (`docs/57 §11`): Ohne `ForceCommand` in der Konfiguration wird
     * `command="…"` ausgeführt. Die zweite Wand steht — und ist kein Grund für
     * ein Loch in der ersten.
     */
    public function test_a_line_with_options_in_front_is_refused(): void
    {
        $this->assertRefused(
            'command="/usr/bin/id" '.self::ED25519,
            'Schlüsseltyp',
        );
        $this->assertRefused('restrict '.self::ED25519, 'Schlüsseltyp');
    }

    /**
     * Ein Steuerzeichen macht aus einer Zeile zwei — jedes, nicht nur `\n`.
     *
     * Der zweite Zugang stünde in der Datei und in keiner Anzeige.
     */
    public function test_a_control_character_is_refused(): void
    {
        foreach (["\n", "\r", "\t", "\x1b"] as $zeichen) {
            $this->assertRefused(
                self::ED25519.$zeichen.self::ED25519,
                'Steuerzeichen',
                'Mit '.bin2hex($zeichen).' dazwischen ist die Eingabe durchgegangen.',
            );
        }

        // Das Nullbyte faengt eine Schicht frueher: `Guard::string()` weist es
        // fuer *jedes* Feld ab, das ueber den Socket kommt. Hier steht es
        // trotzdem, damit niemand die Abdeckung an der Liste oben abliest.
        $this->assertRefused(self::ED25519."\x00".self::ED25519, 'Nullbyte');
    }

    /** Der private Schlüssel wird als solcher benannt und nicht als „unbekannter Typ". */
    public function test_a_private_key_is_named_as_such(): void
    {
        $this->assertRefused('-----BEGIN OPENSSH PRIVATE KEY-----', 'privater');
    }

    /** Abgeschaltete und zu kurze Schlüssel — mit dem Grund, nicht nur mit dem Nein. */
    public function test_dsa_and_short_rsa_are_refused(): void
    {
        $this->assertRefused(self::DSA, '7.0');
        $this->assertRefused(self::RSA_SHORT, '1024 Bit');
    }

    /** Die Aufschrift muss zum Inhalt passen. */
    public function test_the_label_must_match_the_content(): void
    {
        $material = explode(' ', self::ED25519)[1];

        $this->assertRefused('ssh-rsa '.$material, 'passt nicht');
    }

    /**
     * Ein kaputter Schlüssel ergibt **keinen** Fingerabdruck.
     *
     * Das ist der Fall, der ohne diesen Wächter niemandem auffiele: `SHA256:`
     * plus 43 Zeichen sieht immer richtig aus.
     */
    public function test_a_broken_key_never_yields_a_fingerprint(): void
    {
        foreach (['ssh-ed25519', 'ssh-ed25519 !!!!', 'ssh-ed25519 AAAA=AAA', 'ssh-ed25519 QQ=='] as $eingabe) {
            $this->assertRefused($eingabe, '', 'Durchgegangen: '.$eingabe);
        }

        $leer = 'SHA256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU';

        try {
            $abdruck = PublicKey::fingerprint('');
            $this->fail('Über nichts ist ein Fingerabdruck entstanden: '.$abdruck.' (das ist '.$leer.')');
        } catch (AgentException) {
            // So gehört es sich.
        }
    }

    // -------------------------------------------------------- was rausgeht

    /**
     * Die geschriebene Zeile trägt `restrict` und unsere Beschriftung.
     *
     * Geprüft an der erzeugten Zeichenkette und nicht an einer Datei —
     * derselbe Schnitt wie bei `SiteTemplateTest`: Der Standardschutz ist eine
     * Eigenschaft dessen, was erzeugt wird.
     */
    public function test_the_written_line_carries_restrict_and_our_own_label(): void
    {
        $zeile = PublicKey::line(PublicKey::parse(self::ED25519), 'Anna, Laptop');

        $this->assertTrue(str_starts_with($zeile, 'restrict ssh-ed25519 AAAA'), 'Die Zeile fängt falsch an: '.$zeile);
        $this->assertStringContainsString('Anna, Laptop', $zeile, 'Die Beschriftung fehlt.');
        $this->assertStringNotContainsString('kunde@beispiel.de', $zeile, 'Der mitgebrachte Kommentar steht noch drin.');
        $this->assertSame(1, substr_count($zeile, "\n") + 1, 'Die Zeile ist keine Zeile.');
    }

    /** Eine Beschriftung mit Umbruch bleibt trotzdem eine Zeile. */
    public function test_the_label_stays_on_one_line(): void
    {
        $zeile = PublicKey::line(PublicKey::parse(self::ED25519), "boes\nMatch User root");

        $this->assertStringNotContainsString("\n", $zeile, 'Die Beschriftung hat die Zeile geteilt.');
        $this->assertStringContainsString('boes Match User root', $zeile, 'Der Umbruch ist nicht zum Leerzeichen geworden.');
    }

    /** Und eine leere ist nicht leer, sondern benannt. */
    public function test_an_empty_label_becomes_a_name(): void
    {
        $this->assertSame('srvpanel', PublicKey::comment('   '));
    }

    // ---------------------------------------------------------------- Werkzeug

    private function assertRefused(string $eingabe, string $enthaelt, string $warum = ''): void
    {
        try {
            PublicKey::parse($eingabe);
        } catch (AgentException $fehler) {
            if ($enthaelt !== '') {
                $this->assertStringContainsString(
                    $enthaelt,
                    $fehler->getMessage(),
                    'Abgewiesen, aber mit einem Satz, der in die falsche Richtung schickt: '.$fehler->getMessage(),
                );
            }

            return;
        }

        $this->fail($warum !== '' ? $warum : 'Die Eingabe ist durchgegangen: '.mb_substr($eingabe, 0, 60));
    }
}
