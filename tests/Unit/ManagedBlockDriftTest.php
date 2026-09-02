<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\ManagedBlocks;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\Ssh\SshdConfig;
use Tests\Support\WithoutPhpComments;

/**
 * Der Vergleich eines verwalteten Bereichs mit dem Sollzustand
 * (A10 Schritt 5b, `docs/98 §3 C` Frage 3).
 *
 * ## Beide Richtungen, und das ist das Kriterium
 *
 * `srvpanel db` hat diese Lehre schon bezahlt: Bis zum 11. August 2026 sah der
 * Abgleich nur Zeilen **ohne** Bestand — die harmlosere Hälfte. Die andere
 * entstand im Abnahmelauf: Ein gescheiterter Schreibvorgang liess seine Zeile
 * im Bestand stehen, das Panel zeigte „erreichbar von …", und in der Datei
 * stand nichts.
 *
 * > **Ein Abgleich, der nur eine Richtung kennt, ist eine halbe Frage.**
 *
 * ## Und warum ein Schreiber
 *
 * `FindingLog::replace()` ersetzt alle Zeilen einer Prüfung. Zwei Klassen, die
 * `block.integrity` schrieben, löschten einander die Befunde weg. Dieser
 * Wächter hält, dass genau eine es tut.
 *
 * Framework-frei.
 */
final class ManagedBlockDriftTest extends TestCase
{
    use WithoutPhpComments;

    /** Die Zeile aus M16 — sie öffnet jede Datenbank dieses Servers für jeden. */
    private const FREMD = 'host all all 0.0.0.0/0 trust';

    private const UNSER = 'host kunde_db kunde_r 203.0.113.5/32 scram-sha-256';

    private function quelle(): string
    {
        return $this->withoutComments((string) file_get_contents(
            (string) (new \ReflectionClass(ManagedBlocks::class))->getFileName(),
        ));
    }

    public function test_a_foreign_line_is_told_apart_from_ours(): void
    {
        [$fremd, $fehlend] = ManagedBlocks::compare([self::UNSER, self::FREMD], [self::UNSER]);

        $this->assertSame([self::FREMD], $fremd, 'Die fremde Zeile kommt als unsere zurück — der Fund aus M16.');
        $this->assertSame([], $fehlend);
    }

    public function test_a_line_from_the_inventory_that_is_gone_is_named(): void
    {
        [$fremd, $fehlend] = ManagedBlocks::compare([], [self::UNSER]);

        $this->assertSame([], $fremd);
        $this->assertSame([self::UNSER], $fehlend, 'Ein gescheiterter Schreibvorgang bliebe stumm — die Hälfte, die srvpanel db 2026 gekostet hat.');
    }

    public function test_a_block_that_matches_yields_nothing(): void
    {
        $this->assertSame([[], []], ManagedBlocks::compare([self::UNSER], [self::UNSER]));
    }

    /** Die Reihenfolge ist keine Auskunft — verglichen werden Mengen. */
    public function test_the_order_of_the_lines_is_not_a_finding(): void
    {
        $a = 'Match User p1000';
        $b = '    ChrootDirectory /var/www/vhosts/kunde.invalid';

        $this->assertSame([[], []], ManagedBlocks::compare([$b, $a], [$a, $b]));
    }

    /**
     * Der Sollzustand kommt aus der Vorlage und wird nicht nachgebaut.
     *
     * Gehalten am Quelltext: Wer hier eine eigene `Match User`-Zeile schriebe,
     * hätte eine zweite Fassung dessen, was `sftp.access` schreibt.
     */
    public function test_the_desired_state_comes_from_the_template(): void
    {
        $quelle = $this->quelle();

        $this->assertStringContainsString('SshdConfig::lines(', $quelle, 'Der Sollzustand des sshd wird nicht aus der Vorlage genommen.');
        $this->assertStringContainsString('$this->remote->orphans(', $quelle);
        $this->assertStringContainsString('$this->remote->missing(', $quelle);

        foreach (['Match User', 'ChrootDirectory', 'ForceCommand', 'host all all'] as $nachbau) {
            $this->assertStringNotContainsString($nachbau, $quelle, sprintf(
                '%s steht hier wörtlich — dann ist der Sollzustand ein zweites Mal geschrieben.',
                $nachbau,
            ));
        }
    }

    /** Die Rollen sind auf beiden Seiten dieselben Wörter. */
    public function test_the_roles_are_the_same_words_on_both_sides(): void
    {
        $quelle = $this->quelle();

        $this->assertStringContainsString('SystemDiagnose::ROLE_HBA', $quelle);
        $this->assertStringContainsString('SystemDiagnose::ROLE_SSHD', $quelle);
        $this->assertNotSame(SystemDiagnose::ROLE_HBA, SystemDiagnose::ROLE_SSHD);
    }

    /**
     * Genau eine Klasse schreibt `block.integrity`.
     *
     * Zwei löschten einander die Befunde weg, und welche zuletzt liefe,
     * entschiede die Reihenfolge des Nachtlaufs.
     */
    public function test_exactly_one_check_writes_the_block_integrity(): void
    {
        $verzeichnis = dirname(__DIR__, 2).'/app/Support/Diagnose/Checks';
        $schreiber = [];

        foreach (glob($verzeichnis.'/*.php') ?: [] as $datei) {
            $quelle = $this->withoutComments((string) file_get_contents($datei));

            if (str_contains($quelle, 'FindingCheck::BlockIntegrity')) {
                $schreiber[] = basename($datei, '.php');
            }
        }

        $this->assertSame(['ManagedBlocks'], $schreiber, sprintf(
            "Diese Prüfungen fassen block.integrity an: %s\n".
            'FindingLog::replace() ersetzt alle Zeilen einer Prüfung — die zweite löschte die Befunde der ersten.',
            implode(', ', $schreiber),
        ));
    }

    /**
     * Ein Befund je Art und nicht je Zeile.
     *
     * Drei fremde Zeilen in einer Datei sind ein Schaden; die Kennung ist
     * `check`+`subject`+`reason`, und drei Zeilen ergäben dieselbe dreimal.
     */
    public function test_three_foreign_lines_are_one_finding(): void
    {
        $findings = ManagedBlocks::judge('/etc/ssh/sshd_config', true, ['a', 'b', 'c'], []);

        $this->assertCount(1, $findings);
        $this->assertSame('foreign_line', $findings[0]['reason']);

        foreach (['a', 'b', 'c'] as $zeile) {
            $this->assertStringContainsString($zeile, (string) $findings[0]['detail'], 'Der Wortlaut nennt die Zeilen nicht — dann sagt der Befund nicht, welche es sind.');
        }
    }

    /** Beide Arten in einer Datei sind zwei Befunde — es sind zwei Schäden. */
    public function test_foreign_and_missing_are_two_findings(): void
    {
        $findings = ManagedBlocks::judge('/etc/ssh/sshd_config', true, [self::FREMD], [self::UNSER]);

        $this->assertSame(['foreign_line', 'line_missing'], array_column($findings, 'reason'));
    }

    /**
     * Kein Block und nichts zu tun ist der Normalzustand.
     *
     * Ein Server ohne Fernzugriff und ohne SFTP-Schlüssel hat keinen Bereich in
     * diesen Dateien. Jede Nacht eine Zeile darüber wäre die Falle aus
     * `docs/98 §4`, an der ein Lauf nach zwei Wochen ungelesen bleibt.
     */
    public function test_no_block_and_nothing_wanted_is_not_a_finding(): void
    {
        $this->assertSame([], ManagedBlocks::judge('/etc/ssh/sshd_config', false, [], []));
    }

    /** Kein Block, aber der Bestand führt Regeln: Das ist der fehlende Bereich. */
    public function test_no_block_with_wanted_lines_is_the_missing_block(): void
    {
        $findings = ManagedBlocks::judge('/etc/postgresql/16/main/pg_hba.conf', false, [], [self::UNSER]);

        $this->assertCount(1, $findings);
        $this->assertSame('block_missing', $findings[0]['reason']);
        $this->assertSame('/etc/postgresql/16/main/pg_hba.conf', $findings[0]['subject'], 'Der Gegenstand ist der Pfad — ein Befund ohne Ort erfüllt das Kriterium nicht.');
    }

    /** Ein heiler Block meldet nichts. */
    public function test_an_intact_block_yields_nothing(): void
    {
        $this->assertSame([], ManagedBlocks::judge('/etc/ssh/sshd_config', true, [], []));
    }

    /** Und die Gegenstände, wenn der Agent schweigt, sind die beiden Dateien. */
    public function test_the_subjects_of_an_unreachable_run_are_the_two_files(): void
    {
        $quelle = $this->quelle();

        $this->assertStringContainsString('SshdConfig::FILE', $quelle);
        $this->assertStringContainsString("'pg_hba.conf'", $quelle);
        $this->assertSame('/etc/ssh/sshd_config', SshdConfig::FILE);
    }
}
