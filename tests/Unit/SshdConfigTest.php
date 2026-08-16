<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * Der verwaltete Block in `sshd_config` — geprüft an der erzeugten Zeichenkette.
 *
 * Dieselbe Bauart wie `SiteTemplateTest` und `PhpIsolationTest`: Der Schutz ist
 * eine Eigenschaft dessen, was erzeugt wird, und nicht eines Systemzustands.
 * Dieser Container hat keinen `sshd`; was hier steht, muss auch ohne ihn gelten.
 *
 * **Die Regel, die ohne eine Messung niemand aufgestellt hätte**, steht in
 * {@see self::test_the_block_ends_with_a_terminator()} — und `docs/57 §6` ist
 * der Grund.
 *
 * **Die Brüche dazu** (`tests/waechter-brechen.sh`): den Abschluss weglassen;
 * die Schlüsseldatei in das Chroot legen; den Pfad statt des Namens annehmen.
 */
final class SshdConfigTest extends TestCase
{
    /** Ein Bestand, wie ihn Ubuntu 24.04 mitbringt — Include oben, Match unten. */
    private function existing(): string
    {
        return "Include /etc/ssh/sshd_config.d/*.conf\n"
            ."PermitRootLogin prohibit-password\n"
            ."Subsystem sftp /usr/lib/openssh/sftp-server\n"
            ."\n"
            ."Match Group betrieb\n"
            ."    PasswordAuthentication yes\n";
    }

    /** @return list<array{user: string, name: string}> */
    private function accesses(): array
    {
        return [
            ['user' => 'p1136', 'name' => 'p6-b.invalid'],
            ['user' => 'p1001', 'name' => 'beispiel.de'],
        ];
    }

    /**
     * Der Block endet mit `Match all` — für sshd und nicht nur für den Leser.
     *
     * Gemessen (`docs/57 §6`): Eine nicht eingerückte Zeile hinter einem
     * `Match`-Block gehört noch zu ihm, und `sshd -t` meldet dazu `rc=0`. Ohne
     * diesen Abschluss fiele die nächste Zeile, die der Betreiber an seine
     * Datei hängt, in unseren letzten Block.
     *
     * > **Eine Endmarke sagt, wo unser Text aufhört. Sie sagt nicht, wo seine
     * > Wirkung aufhört.**
     */
    public function test_the_block_ends_with_a_terminator(): void
    {
        $lines = SshdConfig::lines($this->accesses());

        $this->assertSame(
            SshdConfig::TERMINATOR,
            end($lines),
            'Der Block hört auf, ohne aufzuhören — alles, was der Betreiber danach schreibt, '
            .'gilt dann nur noch für den letzten Kunden.',
        );

        // Und er steht **innerhalb** des Bereichs: Was hinter `# END srvpanel`
        // steht, gehört dem Betreiber.
        $text = SshdConfig::render($this->existing(), $this->accesses());
        $ende = strpos($text, ManagedBlock::END);
        $abschluss = strpos($text, "\n".SshdConfig::TERMINATOR."\n");

        $this->assertTrue(is_int($ende) && is_int($abschluss), 'Marke oder Abschluss fehlen.');
        $this->assertTrue(
            (int) $abschluss < (int) $ende,
            'Der Abschluss steht hinter der Endmarke und damit im Bestand des Betreibers.',
        );
    }

    /**
     * Der Bestand bleibt, und der Block steht dahinter.
     *
     * Vom Dateiende aus gewinnt, was der Betreiber selbst eingetragen hat
     * (`docs/57 §7`: der erste passende `Match` gilt) — „der Bestand ist Gesetz"
     * wörtlich statt dem Sinne nach.
     */
    public function test_the_block_goes_below_what_the_operator_wrote(): void
    {
        $text = SshdConfig::render($this->existing(), $this->accesses());

        $this->assertStringContainsString($this->existing(), $text, 'Der Bestand ist verändert worden.');
        $this->assertTrue(
            strpos($text, 'Match Group betrieb') < strpos($text, ManagedBlock::BEGIN),
            'Unser Block steht über dem Bestand — dann schlägt er ihn.',
        );
    }

    /**
     * Die Schlüsseldatei liegt ausserhalb jedes Chroots.
     *
     * Läge sie im Abonnement, könnte der Kunde sie über genau den Zugang
     * ändern, den sie gewährt — und die Fingerabdrücke im Panel wären eine
     * Auskunft über die Hälfte der Wahrheit.
     */
    public function test_the_key_file_is_out_of_the_customers_reach(): void
    {
        $block = implode("\n", SshdConfig::block('p1136', '/var/www/vhosts/p6-b.invalid'));

        $this->assertStringContainsString('AuthorizedKeysFile /etc/srvpanel/ssh/p1136', $block);
        $this->assertStringNotContainsString(
            'AuthorizedKeysFile /var/www',
            $block,
            'Die Schlüsseldatei liegt im Abonnement — dort schreibt der Kunde.',
        );
    }

    /** Kein Passwort, kein Terminal, keine Weiterleitung, und ein erzwungener Befehl. */
    public function test_the_block_forces_sftp_and_nothing_else(): void
    {
        $block = implode("\n", SshdConfig::block('p1136', '/var/www/vhosts/p6-b.invalid'));

        foreach ([
            'ForceCommand internal-sftp',
            'PasswordAuthentication no',
            'PermitTTY no',
            'AllowTcpForwarding no',
            'X11Forwarding no',
        ] as $zeile) {
            $this->assertStringContainsString($zeile, $block, 'Es fehlt: '.$zeile);
        }
    }

    /**
     * Der Pfad wird gebaut und nicht angenommen.
     *
     * Wortgleich die wichtigste Entscheidung aus `SubscriptionProvision`: Eine
     * Operation, die einen Pfad annimmt und ihn danach prüft, ist eine
     * Operation, deren Prüfung irgendwann eine Lücke hat.
     */
    public function test_the_chroot_is_built_from_the_name(): void
    {
        $lines = SshdConfig::lines([['user' => 'p1136', 'name' => 'p6-b.invalid']]);

        $this->assertStringContainsString('ChrootDirectory /var/www/vhosts/p6-b.invalid', implode("\n", $lines));

        foreach (['../../etc', '/etc/passwd', 'p6-b.invalid/../..'] as $boes) {
            $this->assertRefused(['user' => 'p1136', 'name' => $boes]);
        }
    }

    /**
     * Ein Zeilenumbruch in einem Namen macht aus einem Block zwei.
     *
     * Gemessen (`docs/57 §11`): untergeschoben wurde `PermitRootLogin yes` und
     * ein `ChrootDirectory /` für einen Benutzer, den der Aufruf nicht nannte —
     * und `sshd -t` meldete `rc=0`. Dieselbe Einschleusung wie in
     * `docs/51 §10.1` für `/etc/cron.d`.
     */
    public function test_a_newline_in_a_name_never_becomes_a_second_block(): void
    {
        $this->assertRefused(['user' => 'p1136', 'name' => "gut.de\n    PermitRootLogin yes"]);
        $this->assertRefused(['user' => "p1136\nMatch User root", 'name' => 'gut.de']);
        $this->assertRefused(['user' => 'root', 'name' => 'gut.de']);
        $this->assertRefused(['user' => 'www-data', 'name' => 'gut.de']);
    }

    /** Ohne Zugang bleibt kein leerer Rumpf stehen. */
    public function test_no_access_leaves_no_block_behind(): void
    {
        $mit = SshdConfig::render($this->existing(), $this->accesses());
        $ohne = SshdConfig::render($mit, []);

        $this->assertSame($this->existing(), $ohne, 'Es ist etwas liegengeblieben.');
        $this->assertStringNotContainsString(ManagedBlock::BEGIN, $ohne);
    }

    /** Zwei Zeilen für denselben Benutzer werden eine — die zweite käme nie zum Zug. */
    public function test_the_same_user_twice_yields_one_block(): void
    {
        $lines = SshdConfig::lines([
            ['user' => 'p1136', 'name' => 'a.invalid'],
            ['user' => 'p1136', 'name' => 'a.invalid'],
        ]);

        $this->assertSame(1, substr_count(implode("\n", $lines), 'Match User p1136'));
    }

    /** @param array{user: string, name: string} $access */
    private function assertRefused(array $access): void
    {
        try {
            SshdConfig::lines([$access]);
        } catch (AgentException) {
            return;
        }

        $this->fail('Durchgegangen: '.json_encode($access));
    }
}
