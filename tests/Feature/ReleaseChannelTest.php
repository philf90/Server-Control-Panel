<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Vorgabe: In der Entwicklung erscheint jede Fassung als `-rc.N` im
 * Beta-Kanal.
 *
 * **Warum das ein Test ist und nicht nur ein Schritt im Freigabelauf.** Ein
 * Wächter, der ausschliesslich in `release.yml` steht, lässt sich nicht
 * gegenprüfen: Man müsste einen Tag pushen, um ihn brechen zu sehen, und
 * genau das will man nicht. Deshalb steht die Regel in einem Skript, der
 * Freigabelauf ruft es auf, und hier wird es mit einer Tabelle durchgefahren.
 * Wer das Muster ändert, sieht den Test zubeissen, ohne dass jemals eine
 * Freigabe schiefgehen musste.
 *
 * Der Fehler, gegen den sich das richtet, ist ein **grüner Lauf**: Ein Tag
 * `v0.3.0` statt `v0.3.0-rc.1` bricht nichts ab. Das Paket wird gebaut,
 * signiert und veröffentlicht — im Kanal `stable`, wo die Server im
 * Beta-Kanal es nie sehen und `srvpanel update` „nichts Neues" meldet.
 */
final class ReleaseChannelTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = sys_get_temp_dir().'/srvpanel-stable-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);

        parent::tearDown();
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Das Skript aufrufen.
     *
     * @return array{status: int, out: string}
     */
    private function channelFor(string $version, ?string $stable = null): array
    {
        if ($stable === null) {
            @unlink($this->marker);
        } else {
            file_put_contents($this->marker, "# Kommentar\n".$stable."\n");
        }

        $output = [];
        $status = 0;

        exec(sprintf(
            'SRVPANEL_STABLE_MARKER=%s %s %s 2>&1',
            escapeshellarg($this->marker),
            escapeshellarg($this->root().'/packaging/version-channel.sh'),
            escapeshellarg($version),
        ), $output, $status);

        return ['status' => $status, 'out' => implode("\n", $output)];
    }

    /**
     * Die Tabelle. Links steht, was jemand taggen könnte; rechts, was daraus
     * werden darf.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function versions(): array
    {
        return [
            // Der Normalfall der Entwicklungsphase.
            'rc.1' => ['0.3.0-rc.1', 'beta'],
            'rc.16' => ['0.2.0-rc.16', 'beta'],
            'zweistellig' => ['1.10.3-rc.42', 'beta'],

            // Der Fehler, um den es geht: kein Zusatz, also Kanal stable.
            'ohne Zusatz' => ['0.3.0', null],

            // Der Tag mit dem Punkt hinter dem v. `${GITHUB_REF_NAME#v}` macht
            // daraus `.0.3.0-rc.1`; dpkg verlangt vorn eine Ziffer, und der
            // Lauf bräche sonst erst beim Paketbau ab — nach dem Tag.
            'Punkt hinter dem v' => ['.0.3.0-rc.1', null],

            // Das führende v gehört an den Tag, nicht an die Fassung.
            'v mitgeschleppt' => ['v0.3.0-rc.1', null],

            // Zwei Schreibweisen derselben Sache sortieren irgendwann falsch.
            'rc ohne Punkt' => ['0.3.0-rc1', null],
            'grosses RC' => ['0.3.0-RC.1', null],
            'beta statt rc' => ['0.3.0-beta.1', null],
            'rc.0' => ['0.3.0-rc.0', null],

            // Unfug, der trotzdem einmal in einem Tag stehen wird.
            'leer' => ['', null],
            'nur Text' => ['fassung', null],
            'zu kurz' => ['0.3-rc.1', null],
            'Leerzeichen' => ['0.3.0 -rc.1', null],
        ];
    }

    /** @param  ?string  $expected  Der Kanal — oder null, wenn abgewiesen wird. */
    #[DataProvider('versions')]
    public function test_the_version_decides_the_channel(string $version, ?string $expected): void
    {
        $result = $this->channelFor($version);

        if ($expected === null) {
            $this->assertSame(1, $result['status'], sprintf(
                'Die Fassung „%s" wurde durchgelassen. Der Freigabelauf hätte sie gebaut, signiert und veröffentlicht. Ausgabe: %s',
                $version,
                $result['out'],
            ));

            // Und die Abweisung sagt, was zu tun ist. Eine Meldung, die nur
            // „ungültig" sagt, kostet den Nächsten eine halbe Stunde.
            $this->assertNotSame('', trim($result['out']), 'Abgewiesen, aber ohne Begründung.');

            return;
        }

        $this->assertSame(0, $result['status'], $result['out']);
        $this->assertSame($expected, trim($result['out']));
    }

    /**
     * Der Ausgang aus der Beta-Phase — und dass er kein Freibrief ist.
     *
     * Die Marke nennt eine Fassung namentlich. Ein Schalter „stabil erlaubt"
     * wäre einmal umgelegt und danach für jeden weiteren Tag gültig; ein Name
     * gilt für genau eine Freigabe.
     */
    public function test_a_stable_release_needs_its_own_name_in_the_marker(): void
    {
        $benannt = $this->channelFor('0.3.0', '0.3.0');

        $this->assertSame(0, $benannt['status'], 'Die benannte Fassung kommt nicht durch: '.$benannt['out']);
        $this->assertSame('stable', trim($benannt['out']));

        // Die nächste Fassung braucht wieder einen Commit.
        $this->assertSame(1, $this->channelFor('0.3.1', '0.3.0')['status'], 'Die Marke wirkt als Freibrief für jede spätere Fassung.');

        // Und die Form gilt weiter: Eine unbrauchbare Fassung kommt auch dann
        // nicht durch, wenn sie in der Marke steht.
        $this->assertSame(1, $this->channelFor('.0.3.0', '.0.3.0')['status'], 'Die Marke hebelt die Formprüfung aus.');

        // Eine rc-Fassung geht nach beta, ganz gleich was in der Marke steht —
        // die Marke ist der Ausgang für stabile Fassungen, kein Umschalter.
        $this->assertSame('beta', trim($this->channelFor('0.3.0-rc.1', '0.3.0')['out']));
    }

    /** Solange die Entwicklung läuft, ist die Marke leer. */
    public function test_the_marker_is_empty_while_development_runs(): void
    {
        $inhalt = (string) file_get_contents($this->root().'/packaging/stable-release');

        $wert = trim(implode('', array_filter(
            explode("\n", $inhalt),
            static fn (string $zeile): bool => ! str_starts_with(trim($zeile), '#'),
        )));

        if ($wert === '') {
            $this->addToAssertionCount(1);

            return;
        }

        // Steht doch eine drin, muss sie wenigstens die richtige Form haben —
        // sonst weist der Wächter die Freigabe ab, für die die Marke gesetzt
        // wurde.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/D', $wert, sprintf(
            'packaging/stable-release nennt „%s". Das ist keine Fassung der Form X.Y.Z.',
            $wert,
        ));
    }

    /**
     * Und der Freigabelauf ruft das Skript auch auf.
     *
     * Die Lücke, die dieses Projekt kennt: fertig gebaut, von nichts
     * aufgerufen. In P3 ist sie zweimal aufgetreten.
     */
    public function test_the_release_workflow_calls_the_guard(): void
    {
        $this->assertStringContainsString(
            'packaging/version-channel.sh',
            (string) file_get_contents($this->root().'/.github/workflows/release.yml'),
            'Der Freigabelauf ruft den Wächter nicht auf — dann steht die Regel in einer Datei, die niemand liest.',
        );
    }

    /**
     * Der Kanal wird an genau einer Stelle bestimmt.
     *
     * Vorher stand die Ableitung zweimal da — einmal für das GitHub-Release,
     * einmal für die Paketquelle. Zwei Stellen, die dieselbe Frage
     * beantworten, geben irgendwann zwei Antworten, und dann liegt das Paket
     * im einen Kanal und ist im anderen als Vorabfassung ausgewiesen.
     */
    public function test_nothing_else_derives_the_channel(): void
    {
        $release = (string) file_get_contents($this->root().'/.github/workflows/release.yml');

        $this->assertDoesNotMatchRegularExpression(
            '/case\s+"\$\{?\{?[^"]*(VERSION|version)[^"]*"\s+in\s*\n?\s*\*-\*/',
            $release,
            'Der Kanal wird noch an einer zweiten Stelle aus der Fassung abgeleitet.',
        );

        $this->assertSame(
            1,
            substr_count($release, 'packaging/version-channel.sh'),
            'Der Wächter wird mehr als einmal aufgerufen — dann ist wieder offen, welcher Aufruf gilt.',
        );
    }

    /**
     * Jedes Shell-Skript unter `packaging/` wird von shellcheck gesehen.
     *
     * Die Liste im CI-Lauf ist von Hand geführt. Ein neues Skript landet nicht
     * von selbst darin — es liefe still ungeprüft mit, und das ist dieselbe
     * Sorte Lücke wie eine Policy ohne Route. Aufgefallen ist es beim Anlegen
     * von `version-channel.sh`.
     */
    public function test_every_packaging_script_is_checked_by_shellcheck(): void
    {
        $ci = (string) file_get_contents($this->root().'/.github/workflows/ci.yml');

        $scripts = glob($this->root().'/packaging/*.sh') ?: [];

        $this->assertGreaterThanOrEqual(4, count($scripts), 'Das Glob findet keine Skripte mehr.');

        foreach ($scripts as $script) {
            $name = basename($script);

            $this->assertTrue(
                str_contains($ci, 'packaging/'.$name) || str_contains($ci, 'packaging/*.sh'),
                sprintf('packaging/%s wird von shellcheck nicht geprüft.', $name),
            );
        }
    }

    /** Ausführbar — sonst scheitert der Aufruf im Freigabelauf. */
    public function test_the_guard_is_executable(): void
    {
        $this->assertTrue(is_executable($this->root().'/packaging/version-channel.sh'));
    }
}
