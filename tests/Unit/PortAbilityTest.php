<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ports\ServerPorts;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Der Prozessname gehört dem Betreiber, und er wird in der Nutzlast entfernt.
 *
 * **Entschieden am 7. September 2026** (`docs/109 §2`, Frage 2). Eine
 * Portnummer ist dieselbe Art Auskunft wie eine installierte Paketfassung, und
 * die darf der Administrator sehen (`docs/81 §3` Frage 2). `ss -ltnp` nennt
 * daneben Prozessnamen — dass auf 25 ein `postfix` sitzt und auf 11211 ein
 * `memcached`, ist keine Zugangsdatei und kein Weg zu root, aber es ist eine
 * Landkarte.
 *
 * **Und der Schnitt liegt im Controller, nicht in der Vorlage.**
 *
 * > **Eine Grenze, die erst im Browser gezogen wird, ist keine.** Ein `v-if`
 * > verbirgt den Namen im Bild und schickt ihn trotzdem über die Leitung —
 * > sichtbar für jeden, der die Antwort ansieht.
 *
 * Framework-frei: `ServerPorts` ist eine reine Umformung, und der Controller
 * wird als Text gelesen.
 */
final class PortAbilityTest extends TestCase
{
    use WithoutPhpComments;

    private const CONTROLLER = __DIR__.'/../../app/Http/Controllers/ServicesController.php';

    private const SEITE = __DIR__.'/../../resources/js/Pages/Services/Index.vue';

    /**
     * Ein Zustand, wie ihn `system.ports` liefert.
     *
     * @return array{
     *     readable: bool,
     *     privileged: bool,
     *     listeners: list<array{address: string, port: int, family: string, scope: string, process: ?string, pid: ?int}>
     * }
     */
    private static function zustand(): array
    {
        return [
            'readable' => true,
            'privileged' => true,
            'listeners' => [
                ['address' => '0.0.0.0', 'port' => 3306, 'family' => 'inet', 'scope' => 'any', 'process' => 'mariadbd', 'pid' => 1234],
                ['address' => '127.0.0.1', 'port' => 25, 'family' => 'inet', 'scope' => 'loopback', 'process' => 'postfix', 'pid' => 42],
            ],
        ];
    }

    /**
     * Gefiltert bleibt kein Name und keine Nummer übrig.
     *
     * Gemessen an der **Wirkung** und nicht am Quelltext: Ein Wächter über die
     * Zeile, die filtert, sagt nicht, dass hinterher nichts mehr dasteht.
     */
    public function test_the_filter_leaves_neither_name_nor_pid(): void
    {
        $ohne = ServerPorts::withoutProcesses(self::zustand());

        foreach ($ohne['listeners'] as $lauscher) {
            $this->assertNull($lauscher['process']);
            $this->assertNull($lauscher['pid']);
        }

        // Und die Gegenprobe: ungefiltert steht beides da. Ohne sie wäre der
        // Fall oben auch dann grün, wenn der Prüfkörper gar keinen Namen trüge.
        $mit = self::zustand();
        $this->assertSame('mariadbd', $mit['listeners'][0]['process']);
        $this->assertSame(1234, $mit['listeners'][0]['pid']);
    }

    /**
     * Der Port bleibt — gefiltert wird der Name, nicht die Zeile.
     *
     * Wer den ganzen Lauscher entfernte, nähme dem Administrator die Auskunft,
     * die er ausdrücklich haben soll.
     */
    public function test_the_port_itself_survives_the_filter(): void
    {
        $ohne = ServerPorts::withoutProcesses(self::zustand());

        $this->assertCount(2, $ohne['listeners']);
        $this->assertSame(3306, $ohne['listeners'][0]['port']);
        $this->assertSame('any', $ohne['listeners'][0]['scope']);
        $this->assertSame(25, $ohne['listeners'][1]['port']);
    }

    /**
     * `privileged` fällt mit.
     *
     * Es sagt, ob der **Agent** nachsehen durfte. Bliebe es auf `true`, machte
     * die Seite daraus „keiner sichtbar" — eine Aussage über den Server, wo in
     * Wahrheit eine über den Betrachter steht.
     *
     * > **Ein Feld, das erklärt, warum eine Spalte leer ist, ist falsch, wenn
     * > die Spalte aus einem anderen Grund leer ist.**
     */
    public function test_the_privilege_flag_falls_with_the_names(): void
    {
        $this->assertNull(ServerPorts::withoutProcesses(self::zustand())['privileged']);
        $this->assertTrue(self::zustand()['privileged'], 'Der Prüfkörper trägt das Feld — sonst misst der Fall nichts.');
    }

    /** Ein Zustand ohne Lauscher geht unverändert durch und wirft nicht. */
    public function test_a_state_without_listeners_passes_through(): void
    {
        $fehler = ['readable' => false, 'reason' => 'unreadable'];

        $this->assertSame($fehler, ServerPorts::withoutProcesses($fehler));
    }

    /**
     * Der Controller filtert, und er fragt dafür die Fähigkeit.
     *
     * Gelesen ohne Kommentare — sonst hielte der Kommentar, der die Regel
     * erklärt, den Wächter grün.
     */
    public function test_the_controller_filters_by_ability(): void
    {
        $quelle = $this->withoutComments((string) file_get_contents(self::CONTROLLER));

        $this->assertStringContainsString('AdminAbility::OPERATE_SERVER', $quelle);
        $this->assertStringContainsString('ServerPorts::withoutProcesses', $quelle);
    }

    /**
     * Und die Vorlage entscheidet es **nicht**.
     *
     * Ein `v-if` auf die Fähigkeit wäre die zweite Fassung derselben Regel —
     * und die zweite ist die, die veraltet. Schlimmer noch: Sie stünde hinter
     * der Nutzlast, in der der Name dann trotzdem reiste.
     */
    public function test_the_template_does_not_decide_it(): void
    {
        $quelle = (string) file_get_contents(self::SEITE);
        $ohne = preg_replace('/<!--.*?-->/su', '', $quelle) ?? $quelle;

        foreach (['operate-server', 'OPERATE_SERVER', 'abilities.operate'] as $spur) {
            $this->assertStringNotContainsString(
                $spur,
                $ohne,
                'Die Vorlage entscheidet über den Prozessnamen — dann reist er trotzdem über die Leitung.',
            );
        }
    }
}
