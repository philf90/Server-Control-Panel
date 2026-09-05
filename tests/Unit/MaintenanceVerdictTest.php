<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use Tests\Support\MethodBody;

/**
 * `guard_missing` — die Wache in jedem Block, gemessen an der Datei
 * (`docs/101 §5`).
 *
 * ## Warum es diese Prüfung neben `directive_lost` gibt
 *
 * Gemessen am 5. September 2026 an einer gerenderten Vhost-Datei: Wird die
 * **ganze** Wache aus einem Block entfernt, meldet die Zusage je Anweisungsname
 * vier fehlende Anweisungen — das genügt. Wird **nur die Zeile mit der
 * ACME-Ausnahme** entfernt, meldet sie **nichts**: `if` steht ja weiterhin
 * dreimal in der Datei.
 *
 * > **Eine Zusage über Anweisungsnamen sieht eine fehlende Zeile nicht, wenn
 * > ihr Name noch anderswo vorkommt.**
 *
 * Und diese Zeile ist die teuerste von allen: Ohne sie antwortet die
 * Prüfadresse von ACME während jeder Wartung mit 503, `nginx -t` gibt dabei
 * `rc=0` (M26), und die Zertifikatserneuerung stirbt lautlos.
 *
 * ## Was er nicht kann
 *
 * Er liest den erzeugten Text. Dass nginx sich an ihn hält, ist gegen
 * nginx 1.24.0 gemessen und steht in `docs/102 §6`.
 */
final class MaintenanceVerdictTest extends TestCase
{
    use MethodBody;

    private ?string $root = null;

    /** @return array<string, mixed> */
    private function args(): array
    {
        return [
            'subscription' => 'p1000',
            'user' => 'p1000',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
            'php_version' => '8.4',
            'maintenance_until' => '2026-09-05 13:35',
            'maintenance_zone' => 'CEST (UTC+02:00)',
        ];
    }

    private function conf(): string
    {
        return SiteTemplate::render(Site::fromArgs($this->args()));
    }

    /** Eine heile Datei ist kein Befund — sonst meldete jede Nacht jede Domain. */
    public function test_an_intact_file_is_no_finding(): void
    {
        $this->assertNull(Verdict::guard($this->conf()));
    }

    /**
     * Die Zeile, die `directive_lost` nicht sieht.
     *
     * **Beide Richtungen in einem Fall**, und das ist die Aussage: Die Zusage
     * schweigt, diese Prüfung meldet. Stünden sie getrennt, sagte keine von
     * beiden etwas über die andere.
     */
    public function test_the_missing_exception_is_invisible_to_the_promise_and_visible_here(): void
    {
        $conf = $this->conf();
        $ohne = preg_replace('/^\s*if \(\$request_uri.*$\n/m', '', $conf);

        $this->assertIsString($ohne);
        $this->assertNotSame($conf, $ohne, 'Der Eingriff hat nichts verändert — dann misst dieser Fall nichts.');

        $this->assertSame(
            [],
            Statements::lostInNginx($ohne, SiteTemplate::promised(SiteTemplate::FORM_PHP, false)),
            'Die Zusage sieht die fehlende Zeile plötzlich — dann prüft dieser Fall etwas anderes.',
        );

        $verdict = Verdict::guard($ohne);

        $this->assertIsArray($verdict);
        $this->assertSame('guard_missing', $verdict['reason']);
        $this->assertStringContainsString('$request_uri', $verdict['detail']);
    }

    /** Jede einzelne Zeile der Wache zählt — eine nach der anderen entfernt. */
    public function test_every_line_of_the_guard_is_missed(): void
    {
        foreach (Maintenance::guardLines() as $line) {
            $ohne = str_replace($line."\n", '', $this->conf());

            $this->assertNotSame($this->conf(), $ohne, 'Der Eingriff hat nichts verändert: '.$line);

            $verdict = Verdict::guard($ohne);

            $this->assertIsArray($verdict, 'Ohne „'.$line.'" meldet die Prüfung nichts.');
            $this->assertStringContainsString($line, $verdict['detail']);
        }
    }

    /**
     * Eine Wache in **einem** von zwei Blöcken ist ein Befund.
     *
     * Das ist der Fall, an dem es hängt: Eine Domain mit Zertifikat hat zwei
     * Blöcke, und der Inhalt steht im zweiten. Eine Prüfung, die nur fragt „ist
     * die Zeile irgendwo da", spräche eine halbe Wache frei.
     */
    public function test_a_guard_in_only_one_of_two_blocks_is_a_finding(): void
    {
        $mit = SiteTemplate::render(Site::fromArgs($this->args() + ['certificate' => 'beispiel.de']), $this->store());

        $this->assertSame(2, substr_count($mit, 'server {'), 'Es sind nicht zwei Blöcke — dann misst dieser Fall etwas anderes.');
        $this->assertNull(Verdict::guard($mit), 'Zwei heile Blöcke sind ein Befund — dann ist die Zählung falsch.');

        // Nur das **erste** Vorkommen entfernen: der Block auf Port 80.
        $halb = preg_replace('/^\s*if \(\$wartung = 1\).*$\n/m', '', $mit, 1);
        $this->assertIsString($halb);

        $verdict = Verdict::guard($halb);

        $this->assertIsArray($verdict, 'Eine Wache in nur einem von zwei Blöcken bleibt unbemerkt.');
        $this->assertSame('guard_missing', $verdict['reason']);
        $this->assertStringContainsString('2 Server-Block', $verdict['detail']);
    }

    /**
     * Ohne Server-Block kein Urteil.
     *
     * Eine Datei ohne `server {` ist leer oder kaputt, und das sagt
     * {@see Verdict::file()} — in der Sprache, in der der Betreiber sie sieht.
     * Ein `guard_missing` daneben wäre die zweite Meldung über dieselbe Sache.
     */
    public function test_a_file_without_a_server_block_gets_no_verdict(): void
    {
        $this->assertNull(Verdict::guard(''));
        $this->assertNull(Verdict::guard("# nur ein Kommentar\n"));
    }

    /**
     * Und `web.file` **fragt** sie — nicht nur: es gäbe sie.
     *
     * **Die Lücke, die dieser Fall schliesst, hat der Bruchlauf gefunden.** Der
     * erste Eingriff dazu entfernte den Aufruf aus {@see SystemDiagnose} und
     * blieb grün: Jede andere Prüfung liest den Grund aus `Verdict::REASONS`,
     * und die Konstante steht ja weiterhin da. Eine Prüfung, die nie gerufen
     * wird, ist von einer, die es nicht gibt, nicht zu unterscheiden.
     *
     * > **Ein Urteil, das niemand einholt, ist von einem, das es nicht gibt,
     * > nicht zu unterscheiden.**
     *
     * Gefragt wird im **Rumpf** der Methode und nicht in der ganzen Datei: Der
     * Name in einem Kommentar daneben ist kein Aufruf.
     */
    public function test_the_web_file_check_asks_the_guard(): void
    {
        $quelle = (string) file_get_contents(__DIR__.'/../../agent/src/Ops/SystemDiagnose.php');
        $rumpf = $this->methodBody($quelle, 'private function webFiles(');

        $this->assertStringContainsString(
            'Verdict::guard(',
            $rumpf,
            'web.file holt das Urteil über die Wache nicht ein — die Prüfung liefe dann nie.',
        );
    }

    /** Der Grund steht im Katalog des Agenten — sonst wirft der Nachtlauf. */
    public function test_the_reason_is_declared(): void
    {
        $this->assertContains('guard_missing', Verdict::REASONS['web.file']);
        $this->assertNotContains('guard_missing', Verdict::REASONS['php.file'], 'Ein PHP-Pool hat keine Wache.');
    }

    /** Ein Wegwerf-Ablageort, damit der 443er-Block entsteht. */
    private function store(): Store
    {
        $this->root ??= sys_get_temp_dir().'/srvpanel-wache-'.bin2hex(random_bytes(6));

        if (! is_dir($this->root.'/beispiel.de')) {
            mkdir($this->root.'/beispiel.de', 0o750, true);
        }

        file_put_contents($this->root.'/beispiel.de/fullchain.pem', "-----BEGIN-----\n");
        file_put_contents($this->root.'/beispiel.de/privkey.pem', "-----BEGIN-----\n");

        return new Store($this->root);
    }
}
