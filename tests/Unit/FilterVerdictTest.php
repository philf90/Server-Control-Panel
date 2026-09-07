<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\FilterState;
use SrvPanel\Agent\Result;

/**
 * Der Zustand des Regelwerks aus zwei Familien und zwei Verwaltern.
 *
 * Die Prüfkörper sind die gemessenen Ausgaben aus `docs/81 §2.3s`.
 * Framework-frei, damit der Wächter im Gestell des Containers läuft.
 */
final class FilterVerdictTest extends TestCase
{
    /** Beide iptables-Bauarten im unberührten Zustand — gemessen (M11). */
    private const SAUBER = "-P INPUT ACCEPT\n-P FORWARD ACCEPT\n-P OUTPUT ACCEPT\n";

    /** Eine Regel dazu — gemessen (M10). */
    private const MIT_REGEL = self::SAUBER."-A INPUT -p tcp -m tcp --dport 12346 -j DROP\n";

    /** Eine verschärfte Richtlinie **ohne** Regel — gemessen (M11b), drei Zeilen wie oben. */
    private const NUR_RICHTLINIE = "-P INPUT DROP\n-P FORWARD ACCEPT\n-P OUTPUT ACCEPT\n";

    private static function leer(): Result
    {
        return new Result(0, '', '');
    }

    /**
     * **Der Fund, für den es diesen Leser gibt.**
     *
     * Ein Regelwerk über `iptables-legacy` ist für `nft list ruleset`
     * unsichtbar — `rc=0`, keine Ausgabe, also zeichengleich mit „keine
     * Regeln" (M10). Ein Leser, der nur `nft` fragte, meldete für einen Server
     * mit vollständiger Firewall, es gebe keine.
     */
    public function test_a_legacy_ruleset_is_seen_although_nft_is_silent(): void
    {
        $zustand = FilterState::read(self::leer(), new Result(0, self::MIT_REGEL, ''), null, null);

        $this->assertTrue($zustand['readable']);
        $this->assertFalse($zustand['nft']['configured'], 'nft sagt nichts — das ist der gemessene Fall.');
        $this->assertTrue($zustand['legacy']['configured'], 'legacy führt eine Regel.');
        $this->assertTrue($zustand['legacy_only'], 'Genau dieser Fall braucht ein eigenes Feld.');
        $this->assertSame('iptables', $zustand['manager']);

        // Gegenprobe: ohne die Regel darf nichts davon anschlagen — sonst
        // meldete der Wächter den Fall auch dort, wo es ihn nicht gibt.
        $ohne = FilterState::read(self::leer(), new Result(0, self::SAUBER, ''), null, null);
        $this->assertFalse($ohne['legacy']['configured']);
        $this->assertFalse($ohne['legacy_only']);
    }

    /**
     * „Konfiguriert" hängt am Inhalt und nicht an der Zeilenzahl.
     *
     * Der erste Entwurf sagte `lines > 3`. **Gemessen ist das falsch** (M11b):
     * `-P INPUT DROP` ohne eine einzige Regel gibt ebenfalls drei Zeilen — und
     * sperrt alles.
     *
     * > **Eine Zahl, die „unberührt" bedeuten soll, zählt eine geänderte
     * > Standardrichtlinie mit — und die ist genau der Fall, den man sehen
     * > will.**
     */
    public function test_a_tightened_default_policy_counts_as_configured(): void
    {
        $sperrt = FilterState::read(self::leer(), new Result(0, self::NUR_RICHTLINIE, ''), null, null);
        $offen = FilterState::read(self::leer(), new Result(0, self::SAUBER, ''), null, null);

        $this->assertSame(
            3,
            count(array_filter(explode("\n", trim(self::NUR_RICHTLINIE)))),
            'Der Prüfkörper muss dieselbe Zeilenzahl haben wie der unberührte — sonst misst der Fall die Zahl.',
        );
        $this->assertSame(3, count(array_filter(explode("\n", trim(self::SAUBER)))));

        $this->assertTrue($sperrt['legacy']['configured'], 'Eine Richtlinie DROP ist eine Firewall.');
        $this->assertFalse($offen['legacy']['configured']);
    }

    /**
     * Ein nicht installiertes Programm ist nicht dasselbe wie eine leere Antwort.
     *
     * `ufw` und `firewalld` fehlen auf den meisten Servern. Der Runner wirft
     * dafür, die Operation macht ein `null` daraus — und `installed` trägt den
     * Unterschied, den ein `false` bei `configured` allein verschlucken würde.
     */
    public function test_a_missing_program_is_not_an_empty_answer(): void
    {
        $fehlt = FilterState::read(null, null, null, null);

        $this->assertFalse($fehlt['nft']['installed']);
        $this->assertFalse($fehlt['legacy']['installed']);
        $this->assertFalse($fehlt['readable'], 'Ohne eine einzige Antwort ist nichts lesbar.');
        $this->assertSame('unknown', $fehlt['manager'], 'Nicht „keiner" — niemand hat nachgesehen.');

        $da = FilterState::read(self::leer(), new Result(0, self::SAUBER, ''), null, null);
        $this->assertTrue($da['nft']['installed']);
        $this->assertSame('none', $da['manager'], 'Beide gelesen und beide leer — erst dann „keiner".');
    }

    /**
     * `unknown` und `none` sind zwei Antworten.
     *
     * Wer `nft` nicht lesen durfte (M8: `rc=1`, `Operation not permitted`),
     * weiss nicht, ob dort etwas steht. „Keiner" wäre eine Behauptung.
     */
    public function test_unreadable_never_becomes_no_firewall(): void
    {
        $verwehrt = new Result(1, '', "Operation not permitted (you must be root)\n");

        $zustand = FilterState::read($verwehrt, new Result(0, self::SAUBER, ''), null, null);

        $this->assertFalse($zustand['nft']['readable']);
        $this->assertSame('unknown', $zustand['manager']);
        $this->assertTrue($zustand['readable'], 'Eine der beiden Familien hat geantwortet.');

        $beide = FilterState::read($verwehrt, new Result(1, '', 'nope'), null, null);
        $this->assertFalse($beide['readable'], 'Beide stumm heisst nicht feststellbar.');
    }

    /**
     * Der Verwalter wird am **genannten Zustand** gewertet und nicht am Rückgabewert.
     *
     * `firewall-cmd --state` hat vier gemessene Ausgänge (M15), und drei davon
     * beantworten die Frage nicht: `rc=1` (der Interpreter passte nicht),
     * `rc=36` (kein D-Bus), `rc=252` (Dienst aus). Nur `rc=0` mit `running` ist
     * die Antwort.
     *
     * > **Ein Rückgabewert, der aus einem Fehlschlag vor der Frage entsteht,
     * > sieht aus wie eine Antwort auf die Frage.**
     */
    public function test_firewalld_is_judged_by_its_word_and_not_by_its_code(): void
    {
        $sauber = new Result(0, self::SAUBER, '');

        $laeuft = FilterState::read(self::leer(), $sauber, null, new Result(0, "running\n", ''));
        $this->assertSame('firewalld', $laeuft['manager']);

        foreach ([
            'kein Interpreter' => new Result(1, '', "ImportError: cannot import name '_gi'\n"),
            'kein D-Bus' => new Result(36, '', "Error: DBUS_ERROR: Failed to connect to socket\n"),
            'Dienst aus' => new Result(252, '', "not running\n"),
        ] as $was => $antwort) {
            $zustand = FilterState::read(self::leer(), $sauber, null, $antwort);

            $this->assertNotSame('firewalld', $zustand['manager'], sprintf('%s ist kein laufender firewalld.', $was));
        }
    }

    /**
     * ufw sagt selbst, dass es zuständig ist — und wird deshalb zuerst gefragt.
     *
     * Bei aktivem ufw stehen `table ip filter` und `ip6 filter` da (M19). Wer
     * nach der Sichtbarkeit statt nach der Zuständigkeit ginge, nennte hier
     * `nftables` oder `iptables`: richtig beobachtet und falsch beantwortet.
     */
    public function test_ufw_is_named_before_what_merely_stands_there(): void
    {
        $nft = new Result(0, "table ip filter {\n}\ntable ip6 filter {\n}\n", '');

        $aktiv = FilterState::read($nft, new Result(0, self::SAUBER, ''), new Result(0, "Status: active\n", ''), null);
        $this->assertSame('ufw', $aktiv['manager']);

        $aus = FilterState::read($nft, new Result(0, self::SAUBER, ''), new Result(0, "Status: inactive\n", ''), null);
        $this->assertSame('nftables', $aus['manager'], 'Ohne ufw bleibt, was dasteht.');
    }

    /** Jeder Verwalter, den dieser Leser aussprechen kann, steht in der Grundmenge. */
    public function test_every_manager_it_can_say_is_in_the_closed_set(): void
    {
        $sauber = new Result(0, self::SAUBER, '');
        $nft = new Result(0, "table inet firewalld {\n}\n", '');

        $faelle = [
            FilterState::read(self::leer(), $sauber, null, null),
            FilterState::read(self::leer(), new Result(0, self::MIT_REGEL, ''), null, null),
            FilterState::read($nft, $sauber, null, new Result(0, "running\n", '')),
            FilterState::read($nft, $sauber, new Result(0, "Status: active\n", ''), null),
            FilterState::read($nft, $sauber, null, null),
            FilterState::read(null, null, null, null),
        ];

        $genannt = [];

        foreach ($faelle as $zustand) {
            $this->assertContains($zustand['manager'], FilterState::MANAGERS);
            $genannt[$zustand['manager']] = true;
        }

        // Untergrenze: Diese sechs Fälle müssen fünf verschiedene Verwalter
        // treffen. Träfen sie alle denselben, hielte der Fall oben nichts.
        $this->assertGreaterThanOrEqual(5, count($genannt), 'Die Fälle treffen zu wenige verschiedene Verwalter.');
    }
}
