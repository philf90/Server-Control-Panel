<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SystemDiagnose;
use Tests\Support\WithoutPhpComments;

/**
 * Die Diagnose schreibt nichts (A10, `docs/98 §5`).
 *
 * **Das ist die erste Regel der Stufe, und ohne diesen Wächter wäre sie eine
 * Absichtserklärung.** Ein Diagnoselauf, der schreibt, ist der nächste
 * Schreiber in derselben Datei — und `docs/42` hat gemessen, was zwei Schreiber
 * in `pg_hba.conf` anrichten. Die Versuchung ist konkret: `sshd -t` braucht
 * `/run/sshd`, und ein `mkdir` wäre eine Zeile.
 *
 * Gelesen wird der Quelltext **ohne Kommentare** — der Klassenkopf erklärt,
 * warum `ensureRuntime()` hier nicht gerufen wird, und nennt es dabei.
 */
final class DiagnoseWriteTest extends TestCase
{
    use WithoutPhpComments;

    /** Was in diesen Dateien nicht vorkommen darf. */
    private const WRITES = [
        'file_put_contents(', 'fwrite(', 'ftruncate(', 'rename(', 'unlink(', 'mkdir(', 'rmdir(',
        'chmod(', 'chown(', 'chgrp(', 'touch(', 'symlink(', 'copy(',
        'ManagedBlock::put(', 'ManagedBlock::render(', 'ManagedBlock::validated(',
        'ensureRuntime(', 'systemctl', 'systemd-run',
    ];

    /** Was der Agent hier rufen darf — lesende Programme, und nur diese. */
    private const READERS = ['nginx', 'sshd', 'quotaon', 'repquota', 'gpg'];

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/agent/src/Ops/SystemDiagnose.php',
            $root.'/agent/src/Diagnose/Verdict.php',
        ];
    }

    public function test_the_operation_declares_itself_read_only(): void
    {
        $this->assertFalse(SystemDiagnose::mutating());
    }

    public function test_no_diagnose_file_writes(): void
    {
        $geprueft = 0;

        foreach ($this->files() as $file) {
            $this->assertFileExists($file);
            $source = $this->withoutComments((string) file_get_contents($file));
            $geprueft++;

            foreach (self::WRITES as $write) {
                $this->assertStringNotContainsString($write, $source, sprintf(
                    '%s ruft %s — eine Diagnose, die schreibt, ist der nächste Schreiber in derselben Datei.',
                    basename($file),
                    $write,
                ));
            }
        }

        $this->assertSame(2, $geprueft);
    }

    /**
     * Jedes Programm, das die Operation ruft, ist ein Leser.
     *
     * **Gefragt wird an der Aufrufstelle** — `run('…'` und `stream('…'` — und
     * nicht die Zeichenkette irgendwo in der Datei, wie bei `RebootConfirmTest`.
     * Ein Programm, das mit einem anderen Schalter schreibt (`quotaon` ohne
     * `-p` schaltet), wird an seinen Argumenten gehalten.
     */
    public function test_every_program_called_is_a_reader(): void
    {
        $source = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/SystemDiagnose.php'));

        // Zwei Formen: der Aufruf am Runner selbst und der über die Hilfsmethode
        // validator(), die Programm und Argumente durchreicht. Ohne die zweite
        // sähe dieser Wächter die drei Prüfer gar nicht — und seine Untergrenze
        // hat genau das beim ersten Lauf gemeldet.
        preg_match_all(
            '/->(?:run|stream|validator)\\(\\s*(?:\\$context,\\s*)?([^,]+),\\s*\\[([^\\]]*)\\]/',
            $source,
            $calls,
            PREG_SET_ORDER,
        );

        $this->assertGreaterThanOrEqual(4, count($calls), 'Zu wenige Aufrufe gefunden — der Ausdruck misst nichts.');

        foreach ($calls as [, $program, $arguments]) {
            $program = trim($program);

            if (! str_starts_with($program, "'")) {
                // Kein Literal, sondern ein Ausdruck: `PhpVersions::program($version)`
                // — versioniert aus einer Positivliste, und mit `-t` ein Leser.
                $this->assertStringContainsString("'-t'", $arguments, $program.' wird ohne -t gerufen.');

                continue;
            }

            $program = trim($program, "'");

            $this->assertContains($program, self::READERS, $program.' ist kein Leser.');

            if ($program === 'quotaon') {
                $this->assertStringContainsString("'-p'", $arguments, 'quotaon ohne -p schaltet.');
            }

            if (in_array($program, ['nginx', 'sshd'], true)) {
                $this->assertStringContainsString("'-t'", $arguments, $program.' ohne -t startet den Dienst.');
            }
        }
    }
}
