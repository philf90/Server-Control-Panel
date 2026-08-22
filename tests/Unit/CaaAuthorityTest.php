<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dns\Authority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Directories;

/**
 * Darf unsere Zertifizierungsstelle ausstellen — laut CAA?
 *
 * **Warum das überhaupt geprüft wird.** Ein CAA-Satz, der uns nicht nennt,
 * lässt jede Bestellung scheitern, und jeder Fehlversuch zählt bei Let's
 * Encrypt fünf je Konto und Stunde — für **jeden** Kunden dieses Servers. Ein
 * Hinweis vorher ist Schadensbegrenzung und keine Bequemlichkeit.
 *
 * **Die Fälle, an denen eine naive Umsetzung scheitert**, stehen unten einzeln:
 * `issuewild` neben `issue` bei einem Platzhalter, ein Wert mit Angaben hinter
 * dem Namen, ein leerer Wert, und der kritische Satz mit unbekannter Marke.
 */
final class CaaAuthorityTest extends TestCase
{
    private const CA = 'letsencrypt.org';

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $saetze
     * @return list<array{flags: int, tag: string, value: string}>
     */
    private function caa(array $saetze): array
    {
        return array_map(
            static fn (array $satz): array => ['flags' => $satz[0], 'tag' => $satz[1], 'value' => $satz[2]],
            $saetze,
        );
    }

    // ------------------------------------------------------------------
    // Kein CAA ist der richtige Zustand
    // ------------------------------------------------------------------

    public function test_without_any_record_nothing_is_restricted(): void
    {
        $urteil = Authority::judge([], self::CA);

        $this->assertSame(Authority::NONE, $urteil['state']);
        $this->assertNull($urteil['reason'], 'Kein CAA ist kein Befund.');
    }

    /**
     * Ein CAA-Satz, der die Ausstellung gar nicht einschränkt, ebenfalls.
     *
     * `iodef` sagt, wohin ein Missbrauchsbericht geht — über die Ausstellung
     * sagt es nichts.
     */
    public function test_a_record_that_restricts_nothing_is_no_finding(): void
    {
        $urteil = Authority::judge($this->caa([[0, 'iodef', 'mailto:abuse@example.de']]), self::CA);

        $this->assertSame(Authority::NONE, $urteil['state']);
    }

    // ------------------------------------------------------------------
    // Erlaubt und verweigert
    // ------------------------------------------------------------------

    public function test_our_authority_named_is_allowed(): void
    {
        $urteil = Authority::judge($this->caa([[0, 'issue', 'letsencrypt.org']]), self::CA);

        $this->assertSame(Authority::ALLOWED, $urteil['state']);
        $this->assertSame(['letsencrypt.org'], $urteil['issuers']);
    }

    public function test_another_authority_is_refused_and_the_reason_names_it(): void
    {
        $urteil = Authority::judge($this->caa([[0, 'issue', 'digicert.com']]), self::CA);

        $this->assertSame(Authority::REFUSED, $urteil['state']);
        $this->assertStringContainsString('digicert.com', (string) $urteil['reason']);
        $this->assertStringContainsString(self::CA, (string) $urteil['reason']);
    }

    /** Steht unsere Stelle neben einer fremden, ist sie erlaubt. */
    public function test_our_authority_among_others_is_allowed(): void
    {
        $urteil = Authority::judge(
            $this->caa([[0, 'issue', 'digicert.com'], [0, 'issue', 'letsencrypt.org']]),
            self::CA,
        );

        $this->assertSame(Authority::ALLOWED, $urteil['state']);
    }

    /**
     * Ein Wert ohne Namen verbietet jedem die Ausstellung.
     *
     * `issue ";"` ist die ausdrückliche Aussage „niemand" — und keine leere
     * Zeile, die man übersehen dürfte.
     */
    public function test_an_empty_value_forbids_everyone(): void
    {
        $urteil = Authority::judge($this->caa([[0, 'issue', ';']]), self::CA);

        $this->assertSame(Authority::REFUSED, $urteil['state']);
        $this->assertStringContainsString('verbietet jede Ausstellung', (string) $urteil['reason']);
    }

    /**
     * Hinter dem Namen dürfen Angaben stehen — verglichen wird der Teil davor.
     *
     * Ohne das läse `letsencrypt.org; validationmethods=dns-01` als eine
     * fremde Stelle, und das Panel meldete einen Befund für eine Zone, die in
     * Ordnung ist.
     */
    #[DataProvider('werteMitAngaben')]
    public function test_parameters_behind_the_name_are_ignored(string $wert): void
    {
        $this->assertSame(
            Authority::ALLOWED,
            Authority::judge($this->caa([[0, 'issue', $wert]]), self::CA)['state'],
            $wert,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function werteMitAngaben(): iterable
    {
        yield 'mit Prüfverfahren' => ['letsencrypt.org; validationmethods=dns-01'];
        yield 'mit Kontonummer' => ['letsencrypt.org;accounturi=https://acme-v02.api.letsencrypt.org/acme/acct/1'];
        yield 'mit Leerraum' => ['  letsencrypt.org  '];
        yield 'in Grossbuchstaben' => ['LETSENCRYPT.ORG'];
    }

    // ------------------------------------------------------------------
    // Der Platzhalter — die Stelle, an der es schiefgeht
    // ------------------------------------------------------------------

    /**
     * Ist `issuewild` da, gilt für einen Platzhalter **nur** das.
     *
     * **Das ist der Fall, an dem eine naive Umsetzung falsch liegt** — und P4
     * bestellt Platzhalter. Hier erlaubt `issue` uns, `issuewild` erlaubt eine
     * andere Stelle: Für ein gewöhnliches Zertifikat ist das in Ordnung, für
     * einen Platzhalter nicht.
     */
    public function test_issuewild_alone_decides_for_a_wildcard(): void
    {
        $saetze = $this->caa([
            [0, 'issue', 'letsencrypt.org'],
            [0, 'issuewild', 'digicert.com'],
        ]);

        $this->assertSame(Authority::ALLOWED, Authority::judge($saetze, self::CA)['state'], 'gewöhnlich');
        $this->assertSame(
            Authority::REFUSED,
            Authority::judge($saetze, self::CA, wildcard: true)['state'],
            'als Platzhalter',
        );
    }

    /** Fehlt `issuewild`, tritt `issue` an seine Stelle. */
    public function test_without_issuewild_the_issue_tag_counts_for_wildcards_too(): void
    {
        $saetze = $this->caa([[0, 'issue', 'letsencrypt.org']]);

        $this->assertSame(Authority::ALLOWED, Authority::judge($saetze, self::CA, wildcard: true)['state']);
    }

    // ------------------------------------------------------------------
    // Der kritische Satz
    // ------------------------------------------------------------------

    /**
     * Ein zwingender Satz mit unbekannter Marke verbietet die Ausstellung.
     *
     * **Und zwar vor allem anderen.** Wer ihn übersieht, meldet „darf" für
     * eine Zone, die jede Bestellung abweist — obwohl `issue` uns nennt.
     */
    public function test_a_critical_unknown_tag_forbids_issuance(): void
    {
        $urteil = Authority::judge(
            $this->caa([[0, 'issue', 'letsencrypt.org'], [128, 'watdenn', 'egal']]),
            self::CA,
        );

        $this->assertSame(Authority::REFUSED, $urteil['state']);
        $this->assertStringContainsString('watdenn', (string) $urteil['reason']);
    }

    /**
     * Und die Gegenprobe, zweifach: unkritisch unbekannt ist harmlos, kritisch
     * bekannt ebenfalls.
     *
     * **Ohne sie hiesse die vorige Prüfung womöglich nur „irgendein zweiter
     * Satz stört".**
     */
    public function test_the_critical_bit_and_the_unknown_tag_must_meet(): void
    {
        $this->assertSame(
            Authority::ALLOWED,
            Authority::judge($this->caa([[0, 'issue', 'letsencrypt.org'], [0, 'watdenn', 'egal']]), self::CA)['state'],
            'unbekannt, aber nicht zwingend',
        );

        $this->assertSame(
            Authority::ALLOWED,
            Authority::judge($this->caa([[0, 'issue', 'letsencrypt.org'], [128, 'issue', 'letsencrypt.org']]), self::CA)['state'],
            'zwingend, aber bekannt',
        );
    }

    // ------------------------------------------------------------------
    // Die Kennung gehört zur Zertifizierungsstelle
    // ------------------------------------------------------------------

    /**
     * Jede Zertifizierungsstelle, mit der dieser Agent spricht, hat eine
     * CAA-Kennung.
     *
     * **Sonst wäre es wieder eine Zeichenkette, die auf nichts zeigt** — der
     * Fehler, an dem dieses Projekt am häufigsten verloren hat. Trüge eine
     * dritte Stelle keine Kennung, meldete der CAA-Hinweis stillschweigend
     * „darf nicht", weil er den Namen nicht kennt: ein Befund an jeder Zone,
     * die in Ordnung ist.
     */
    public function test_every_directory_has_a_caa_identifier(): void
    {
        $keys = Directories::keys();

        $this->assertNotSame([], $keys, 'Es gibt keine Verzeichnisse — dann prüft das hier nichts.');

        foreach ($keys as $key) {
            $kennung = Directories::caa($key);

            $this->assertNotNull($kennung, sprintf('%s hat keine CAA-Kennung.', $key));
            $this->assertNotSame('', $kennung, sprintf('Die CAA-Kennung von %s ist leer.', $key));
        }
    }

    /**
     * Und ein Schlüssel, den es nicht gibt, hat keine.
     *
     * **`null` und nicht `''`.** Eine leere Kennung wäre in einem CAA-Satz die
     * Aussage „niemand darf" — und das ist etwas ganz anderes als „wir wissen
     * es nicht".
     */
    public function test_an_unknown_directory_has_no_identifier(): void
    {
        $this->assertNull(Directories::caa('gibt-es-nicht'));
        $this->assertNull(Directories::caa(null));
    }

    // ------------------------------------------------------------------
    // Wenn wir unsere eigene Kennung nicht kennen
    // ------------------------------------------------------------------

    /**
     * Ohne bekannte Kennung wird nicht stillschweigend „erlaubt" gemeldet.
     *
     * `null` heisst „wir wissen nicht, wie wir heissen" — und daraus ein
     * „darf" zu machen wäre eine Zusage, für die es keine Grundlage gibt.
     */
    public function test_without_a_known_identifier_nothing_is_promised(): void
    {
        $urteil = Authority::judge($this->caa([[0, 'issue', 'letsencrypt.org']]), null);

        $this->assertSame(Authority::REFUSED, $urteil['state']);
        $this->assertStringContainsString('die verwendete Zertifizierungsstelle', (string) $urteil['reason']);
    }
}
