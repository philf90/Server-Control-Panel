<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Feature\ValidationLanguageTest;

/**
 * Die zusätzliche Gruppe der Sandbox verlangt genau ein Griff.
 *
 * ## Der Fehler, der zweimal ausgeliefert wurde
 *
 * `files.chmod` sollte das geerbte setgid-Bit eines Verzeichnisses mitführen
 * (`docs/55`, Befund 13). Der erste Wurf rechnete `$mode | $geerbt` — richtig
 * gerechnet, und auf `cloudsrv24` stand danach trotzdem `drwxr-xr-x`.
 *
 * **`chmod(2)` entfernt `S_ISGID` lautlos**, wenn der aufrufende Prozess weder
 * `CAP_FSETID` hat noch der Gruppe der Datei angehört — und gibt dabei `true`
 * zurück. Die Sandbox läuft als der Kunde, das Verzeichnis gehört `www-data`,
 * und der Kunde ist dort kein Mitglied. Ein Kunde konnte das Bit auf seinem
 * eigenen Verzeichnis also gar nicht erhalten.
 *
 * > **Ein Rückgabewert `true` ist keine Zusage darüber, was geschrieben
 * > wurde.**
 *
 * ## Und warum kein Wächter das gesehen hat
 *
 * Der Wächter dazu hat die Mechanik im Container gemessen — **als root**. Root
 * hat `CAP_FSETID`, also ging es. Im PR-Formular dieses Projekts steht die
 * Zeile „Tests laufen auch als unprivilegiertes Konto (als root scheitern
 * Dateirechte nicht)"; sie war abgehakt.
 *
 * > **Eine Messung als root prüft nicht, was ein unprivilegierter Prozess
 * > darf.**
 *
 * ## Was dieser Wächter prüft
 *
 * Die **Enge** der Ausnahme. Dass sie wirkt, misst
 * {@see SchemeProtectionTest::test_an_unprivileged_chmod_needs_the_group};
 * dass sie nicht wächst, steht hier. Die Begründung im Quelltext von `Sandbox`
 * gilt für einen Griff, der nichts liest, und nicht als Freibrief: Wer eine
 * zweite Operation mit einer fremden Gruppe laufen lässt, hebt sie auf, ohne
 * dass es jemandem auffiele.
 *
 * > **Eine Ausnahme mit Begründung wird zur Regel, sobald die zweite sich auf
 * > sie beruft.**
 */
final class SandboxGroupTest extends TestCase
{
    /**
     * Operationen, die eine zusätzliche Gruppe verlangen dürfen — mit Grund.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'FilesChmod.php' => 'Ohne `www-data` nimmt der Kernel das geerbte setgid-Bit lautlos weg. '.
            'Der Griff macht einen `chmod` und liest nichts.',
    ];

    /** @return list<string> */
    private function operations(): array
    {
        $gefunden = glob(dirname(__DIR__, 2).'/agent/src/Ops/*.php');

        return $gefunden === false ? [] : array_values($gefunden);
    }

    public function test_only_the_named_operations_ask_for_an_extra_group(): void
    {
        $verlangen = [];

        foreach ($this->operations() as $datei) {
            $quelle = (string) file_get_contents($datei);

            /*
             * Gesucht wird der **dritte** Parameter von `run()` — also ein
             * Aufruf, der nach dem `$close`-Argument noch etwas mitgibt. Ein
             * gewöhnliches `run($work)` oder `run($work, [$socket])` trifft
             * dieser Ausdruck nicht.
             */
            if (preg_match('/\}, \[[^\]]*\], [^)]+\);/', $quelle) === 1) {
                $verlangen[] = basename($datei);
            }
        }

        sort($verlangen);

        /*
         * Ohne `array_values`, und das ist Absicht: PHPStan hält das Ergebnis
         * von `array_diff` über einer Liste schon für eine und meldet den
         * Aufruf als wirkungslos. Für den Fall, auf den es hier ankommt — die
         * leere Liste —, sind Schlüssel ohnehin belanglos.
         */
        $unerlaubt = array_diff($verlangen, array_keys(self::ALLOWED));

        $this->assertSame(
            [],
            $unerlaubt,
            sprintf(
                "Diese Operationen lassen die Sandbox mit einer zusätzlichen Gruppe laufen:\n  %s\n\n".
                "Die Begründung in `Sandbox` gilt für einen Griff, der einen `chmod` macht und nichts\n".
                'liest. Wer sich für etwas anderes darauf beruft, trägt seinen eigenen Grund in '.
                'ALLOWED ein — und schreibt dazu, was der Griff im Chroot alles anfassen kann.',
                implode("\n  ", $unerlaubt),
            ),
        );
    }

    /**
     * Und der Ausdruck findet den einen, den es gibt.
     *
     * **Ohne diese Gegenprobe ist der Test darüber wertlos.** Er behauptet eine
     * leere Liste, und die liefert ein kaputter Ausdruck genauso zuverlässig
     * wie ein enger Gebrauch.
     *
     * > **Eine Messung, die nie etwas anderes als Null liefern kann, ist
     * > keine.**
     */
    public function test_the_search_finds_the_one_that_does(): void
    {
        $quelle = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/FilesChmod.php',
        );

        $this->assertMatchesRegularExpression(
            '/\}, \[[^\]]*\], [^)]+\);/',
            $quelle,
            "`files.chmod` verlangt die zusätzliche Gruppe nicht mehr.\n\n".
            'Dann nimmt der Kernel das geerbte setgid-Bit wieder lautlos weg — und dieser Wächter '.
            'oben findet nichts mehr, ganz gleich wie weit die Ausnahme wächst.',
        );

        $this->assertGreaterThan(
            10,
            count($this->operations()),
            'Es werden kaum Operationen gefunden. Dann liest dieser Wächter am falschen Ort.',
        );
    }

    /**
     * Die Sandbox reicht die Gruppe auch bis zu `initgroups` durch.
     *
     * Zwischen `files.chmod` und dem Systemaufruf liegen zwei Weitergaben
     * (`Workspace::run`, `Sandbox::run`), und jede davon kann das Argument
     * fallen lassen, ohne dass irgendetwas rot wird — der Vorgang läuft dann
     * weiter und tut bloss wieder nichts.
     */
    public function test_the_group_reaches_initgroups(): void
    {
        $workspace = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Files/Workspace.php',
        );

        $this->assertStringContainsString(
            '$work, $close, $withGroup',
            $workspace,
            '`Workspace::run()` reicht die zusätzliche Gruppe nicht an die Sandbox weiter.',
        );

        $sandbox = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Sandbox.php');

        $this->assertStringContainsString(
            "posix_initgroups(\$account['name'], \$account['extra_gid'] ?? \$account['gid'])",
            $sandbox,
            "Die zusätzliche Gruppe kommt bei `initgroups` nicht an.\n\n".
            'Sie ist genau das zweite Argument: `initgroups(3)` nimmt die Gruppen des Benutzers aus '.
            '`/etc/group` **plus** die hier genannte.',
        );
    }

    /** @see ValidationLanguageTest::test_every_exemption_carries_a_reason */
    public function test_every_exemption_carries_a_reason(): void
    {
        foreach (self::ALLOWED as $datei => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                sprintf('`%s` steht ohne Grund in der Ausnahmeliste.', $datei),
            );
        }
    }
}
