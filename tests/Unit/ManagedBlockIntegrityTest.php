<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\ManagedBlock;

/**
 * Der lesende Blick auf einen verwalteten Bereich sagt dasselbe wie der schreibende.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Gemessen am 2. September 2026 (`docs/81 §2.3o` M14, M15): `managed()` gab bei
 * vier verschiedenen Schäden dieselbe leere Liste zurück, und den einen Zustand,
 * den `ManagedBlock` selbst für fatal hält — `BEGIN` ohne `END` — sah nur der
 * Schreibweg. Ein Diagnoselauf, der nichts schreibt, kam an dieser Prüfung
 * nicht vorbei.
 *
 * ## Die Regel, die er hält
 *
 * **Leser und Schreiber meinen denselben Bereich.** `inspect()` liest die Marken
 * wie `without()` — erstes `BEGIN`, erstes `END` danach. Wo der Schreiber wirft,
 * sagt der Leser `begin_without_end`, und nirgends sonst. Gehalten wird das
 * nicht am Quelltext, sondern an der Wirkung: Jeder Prüfkörper geht durch beide.
 *
 * > **Zwei Leser derselben Marken, die verschieden zählen, sind zwei Fassungen
 * > derselben Regel — und die zweite ist die, die veraltet.**
 *
 * Framework-frei; `ManagedBlock` hängt an nichts.
 */
final class ManagedBlockIntegrityTest extends TestCase
{
    private const B = ManagedBlock::BEGIN;

    private const E = ManagedBlock::END;

    /** Ein Bestand mit einem heilen Bereich — der Prüfkörper, aus dem die Schäden entstehen. */
    private function intact(): string
    {
        return "# Bestand des Betreibers\nlocal all postgres peer\n\n".self::B."\nhost db1 u1 10.0.0.0/8 scram-sha-256\nhost db2 u2 10.0.0.0/8 scram-sha-256\n".self::E."\n";
    }

    /**
     * Die neun Formen aus der Messrunde — jede mit ihrem Urteil.
     *
     * Die Tabelle ist die aus `docs/81 §2.3o` M14, um die Spalte ergänzt, die
     * dort fehlte: was der Leser **sagen soll**.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function forms(): array
    {
        $heil = "# Bestand des Betreibers\nlocal all postgres peer\n\n".self::B."\nhost db1 u1 10.0.0.0/8 scram-sha-256\nhost db2 u2 10.0.0.0/8 scram-sha-256\n".self::E."\n";

        return [
            'heil' => [$heil, 'intact', 2],
            'Block ganz entfernt' => ["# Bestand des Betreibers\nlocal all postgres peer\n", 'absent', 0],
            'END von Hand entfernt' => [str_replace(self::E."\n", '', $heil), 'begin_without_end', 2],
            'BEGIN von Hand entfernt' => [str_replace(self::B."\n", '', $heil), 'end_without_begin', 0],
            'Marke mit anderem Text' => [str_replace(self::B, '# BEGIN srvpanel', $heil), 'end_without_begin', 0],
            'eine Zeile im Block fort' => [str_replace("host db2 u2 10.0.0.0/8 scram-sha-256\n", '', $heil), 'intact', 1],
            'fremde Zeile im Block' => [str_replace(self::E, "host alles alle 0.0.0.0/0 trust\n".self::E, $heil), 'intact', 3],
            'Block zweimal' => [$heil."\n".self::B."\nhost db3 u3 10.0.0.0/8 scram-sha-256\n".self::E."\n", 'duplicate', 2],
            'Datei leer' => ['', 'absent', 0],
        ];
    }

    #[DataProvider('forms')]
    public function test_every_form_from_the_measurement_round_gets_its_verdict(string $content, string $state, int $lines): void
    {
        $befund = ManagedBlock::inspect($content);

        $this->assertSame($state, $befund['state']);
        $this->assertCount($lines, $befund['lines'], 'Die Zeilen des Bereichs stimmen nicht mit der Messrunde überein.');
    }

    /**
     * Wo der Schreiber wirft, sagt der Leser `begin_without_end` — und nirgends sonst.
     *
     * Beide Richtungen, an jedem Prüfkörper: Ein Leser, der strenger ist als
     * der Schreiber, meldete Schäden, die der Schreiber nicht kennt; einer, der
     * nachsichtiger ist, verschwiege den, dessentwegen es ihn gibt.
     */
    public function test_the_reader_agrees_with_the_writer_on_a_missing_end(): void
    {
        $geprueft = 0;

        foreach (self::forms() as $name => [$content]) {
            $wirft = false;

            try {
                ManagedBlock::render($content, ['host x y z w'], '/etc/ssh/sshd_config');
            } catch (AgentException) {
                $wirft = true;
            }

            $sagt = ManagedBlock::inspect($content)['state'] === 'begin_without_end';

            $this->assertSame($wirft, $sagt, sprintf(
                '%s: der Schreiber %s, der Leser sagt %s.',
                $name,
                $wirft ? 'wirft' : 'schreibt',
                ManagedBlock::inspect($content)['state'],
            ));

            $geprueft++;
        }

        // Untergrenze — und die Gewissheit, dass der Fall überhaupt vorkam.
        $this->assertSame(9, $geprueft);
        $this->assertSame('begin_without_end', ManagedBlock::inspect(self::forms()['END von Hand entfernt'][0])['state']);
    }

    /**
     * Ein `END` vor dem Bereich gehört zu keinem — für beide.
     *
     * `without()` sucht das erste `END` **nach** dem ersten `BEGIN`; ein
     * verirrtes davor übergeht es. Der Leser tut dasselbe, sonst meldete er
     * einen Bereich als kaputt, den der Schreiber heil vorfindet.
     */
    public function test_a_stray_end_before_the_block_is_ignored_by_both(): void
    {
        $content = self::E."\n".$this->intact();

        $this->assertSame('intact', ManagedBlock::inspect($content)['state']);
        $this->assertSame(['host db1 u1 10.0.0.0/8 scram-sha-256', 'host db2 u2 10.0.0.0/8 scram-sha-256'], ManagedBlock::inspect($content)['lines']);

        // Und der Schreiber lässt es stehen.
        $this->assertStringStartsWith(self::E."\n", ManagedBlock::render($content, ['host a b c d'], '/x'));
    }

    /**
     * Der Leser wirft nie — er berichtet.
     *
     * **Und jede Antwort ist eine.** Ein Leser, der bei einem Schaden wirft,
     * wäre `without()` mit neuem Namen — und eine Diagnose, die an ihrem ersten
     * Fund abbricht, meldet die übrigen dreizehn Prüfungen nicht mehr. Deshalb
     * wird hier nicht „kein Wurf" behauptet, sondern an jedem Prüfkörper ein
     * Urteil verlangt: Das eine ist die Abwesenheit eines Fehlers, das andere
     * die Anwesenheit einer Antwort.
     */
    public function test_inspect_never_throws(): void
    {
        $koerper = array_map(static fn (array $form): string => $form[0], self::forms());

        // Und auch Unrat bekommt ein Urteil.
        $koerper['Unrat'] = "\0\xff\xfe".self::B."\n\xff";
        $koerper['zweihundert BEGIN'] = str_repeat(self::B."\n", 200);

        foreach ($koerper as $name => $content) {
            $befund = ManagedBlock::inspect($content);

            $this->assertContains($befund['state'], ['absent', 'intact', 'begin_without_end', 'end_without_begin', 'duplicate'], $name);
        }

        $this->assertSame('duplicate', ManagedBlock::inspect($koerper['zweihundert BEGIN'])['state']);
    }

    /** Die Zeilen sind die von `managed()` — es gibt nur eine Lesart des Inhalts. */
    public function test_the_lines_are_those_of_managed(): void
    {
        foreach (self::forms() as $name => [$content]) {
            $befund = ManagedBlock::inspect($content);

            if ($befund['begin_lines'] === []) {
                $this->assertSame([], $befund['lines'], $name);

                continue;
            }

            $this->assertSame(ManagedBlock::managed($content), $befund['lines'], $name);
        }
    }

    /**
     * Die Zeilennummern zählen ab 1 und nennen jedes `BEGIN`.
     *
     * Ab 1, weil der Betreiber sie in seinem Editor sucht — und weil
     * `without()` seine Meldung genauso zählt (`Zeile %d`, `$begin + 1`).
     */
    public function test_begin_lines_are_one_based_and_name_every_begin(): void
    {
        $befund = ManagedBlock::inspect(self::forms()['Block zweimal'][0]);

        $this->assertSame([4, 9], $befund['begin_lines']);
        $this->assertSame(7, $befund['end_line']);

        $this->assertSame([], ManagedBlock::inspect('')['begin_lines']);
        $this->assertNull(ManagedBlock::inspect('')['end_line']);
    }

    /**
     * Der Leser fasst keine Datei an — weder lesend noch schreibend.
     *
     * Die Unterschrift sagt es schon (`string $content`, kein Pfad); dieser
     * Test hält es am Rumpf, damit niemand später „nur kurz" einen Pfad
     * durchreicht. Gelesen an den Token und nicht am Text: Ein Kommentar, der
     * `file_get_contents` erwähnt, ist kein Aufruf.
     */
    public function test_inspect_touches_no_file(): void
    {
        // Über die Klasse und nicht über einen Pfad: So misst der Wächter die
        // Datei, die wirklich geladen ist — auch in einem Gestell, das den
        // Agenten von woanders lädt.
        $source = (string) file_get_contents((string) (new \ReflectionClass(ManagedBlock::class))->getFileName());
        $body = $this->methodBody($source, 'inspect');

        $this->assertNotSame('', $body, 'inspect() nicht gefunden — der Wächter misst nichts.');
        $this->assertStringContainsString('managed(', $body, 'Der Rumpf ist zu kurz — der Wächter hat die falsche Methode erwischt.');

        foreach (['file_get_contents', 'fopen', 'file(', 'file_put_contents', 'rename', 'unlink', 'self::read', 'self::put', 'self::locked'] as $verboten) {
            $this->assertStringNotContainsString($verboten, $body, sprintf('inspect() ruft %s — ein Leser, der Dateien anfasst.', $verboten));
        }
    }

    /** Der Rumpf einer Methode, ohne Kommentare — über die Token, nicht über den Text. */
    private function methodBody(string $source, string $method): string
    {
        $tokens = token_get_all($source);
        $inside = false;
        $depth = 0;
        $body = '';

        foreach ($tokens as $index => $token) {
            if (! $inside) {
                if (is_array($token) && $token[0] === T_FUNCTION
                    && is_array($tokens[$index + 2] ?? null) && $tokens[$index + 2][1] === $method) {
                    $inside = true;
                }

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                $depth++;
            }

            if ($depth > 0) {
                $body .= $text;
            }

            if ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }
        }

        return $body;
    }
}
