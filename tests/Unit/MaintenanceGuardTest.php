<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Die Wache des Wartungsmodus — an der **Reihenfolge** und nicht am Wortlaut
 * (A12, `docs/101 §3`).
 *
 * ## Was er hält, und warum kein Prüfer es kann
 *
 * Die Messrunde (`docs/81 §2.3p`) hat zwei Fassungen gemessen, die beide falsch
 * sind und die beide `nginx -t` mit `rc=0` bestehen: eine ohne Ausnahme für die
 * ACME-Prüfadresse (M24, die Zertifikatserneuerung stirbt) und eine in
 * `location /` statt auf Serverebene (M25, PHP läuft weiter).
 *
 * > **Ein Prüfer, der beide Fassungen für gültig hält, sagt über die Wirkung
 * > nichts — und die kaputte ist die, die man zuerst schreibt.**
 *
 * Was hier gehalten wird, ist deshalb die **Anordnung** der vier Zeilen: dass
 * die Ausnahme zwischen dem Setzen und der Entscheidung steht. Eine Wache, in
 * der sie danach steht, ist wirkungslos; eine, in der sie davor steht, wird vom
 * Setzen überschrieben. Beide sähen im Text fast gleich aus.
 *
 * ## Was er nicht kann
 *
 * **Ob nginx sich so verhält, sagt kein Wächter dieses Repos.** Das ist gegen
 * nginx 1.24.0 gemessen, mit Gegenprobe je Fall, und die Zahlen stehen in
 * `docs/81 §2.3p`. Dieser Wächter hält die Form, die dort getragen hat — er
 * belegt sie nicht.
 *
 * > **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
 * > als Zusage.**
 */
final class MaintenanceGuardTest extends TestCase
{
    private ?string $root = null;

    /**
     * Die vier Formen, jede mit den Argumenten, die sie erzeugt.
     *
     * @return array<string, array<string, mixed>>
     */
    private function forms(): array
    {
        $basis = ['subscription' => 'p1000', 'user' => 'p1000', 'domain' => 'beispiel.de'];

        return [
            SiteTemplate::FORM_SUSPENDED => $basis + ['document_root' => 'httpdocs', 'suspended' => true],
            SiteTemplate::FORM_REDIRECT => $basis + ['redirect_target' => 'https://ziel.example'],
            SiteTemplate::FORM_PHP => $basis + ['document_root' => 'httpdocs', 'php_version' => '8.4'],
            SiteTemplate::FORM_STATIC => $basis + ['document_root' => 'httpdocs'],
        ];
    }

    public function test_every_form_carries_the_guard(): void
    {
        foreach ($this->forms() as $form => $args) {
            $conf = SiteTemplate::render(Site::fromArgs($args));

            $this->assertStringContainsString(
                'if (-f '.Maintenance::FLAG.')',
                $conf,
                $form.' trägt die Wache nicht — bei eingeschalteter Wartung liefe diese Domain weiter.',
            );
        }
    }

    /**
     * Die Ausnahme steht **zwischen** dem Setzen und der Entscheidung.
     *
     * Davor überschriebe das Setzen sie, danach käme sie zu spät. Gemessen an
     * den Positionen im Text und nicht am Wortlaut einer Zeile.
     */
    public function test_the_challenge_exception_stands_between_the_switch_and_the_verdict(): void
    {
        foreach ($this->forms() as $form => $args) {
            $conf = SiteTemplate::render(Site::fromArgs($args));

            $setzen = strpos($conf, 'if (-f '.Maintenance::FLAG.')');
            $ausnahme = strpos($conf, 'acme');
            $urteil = strpos($conf, 'if ($wartung = 1)');

            $this->assertIsInt($setzen, $form.': das Setzen fehlt.');
            $this->assertIsInt($ausnahme, $form.': die Ausnahme für die Prüfadresse fehlt.');
            $this->assertIsInt($urteil, $form.': die Entscheidung fehlt.');

            $this->assertLessThan($ausnahme, $setzen, $form.': die Ausnahme steht vor dem Setzen und wird davon überschrieben.');
            $this->assertLessThan($urteil, $ausnahme, $form.': die Ausnahme steht nach der Entscheidung und kommt zu spät.');
        }
    }

    /**
     * Die Ausnahme nennt denselben Pfad wie die Prüfadresse selbst.
     *
     * **Zwei Listen, die dasselbe meinen, laufen auseinander — und die zweite
     * ist die, die veraltet.** Zöge `HttpChallenge::PREFIX` um, ohne dass die
     * Wache mitzöge, stürbe die Zertifikatserneuerung bei jeder Wartung, und
     * kein Prüfer sähe es.
     */
    public function test_the_exception_names_the_challenge_prefix_itself(): void
    {
        $conf = SiteTemplate::render(Site::fromArgs($this->forms()[SiteTemplate::FORM_STATIC]));

        $this->assertStringContainsString(
            'if ($uri ~ ^'.preg_quote(HttpChallenge::PREFIX).'/)',
            $conf,
            'Die Ausnahme nennt einen anderen Pfad als HttpChallenge::PREFIX.',
        );
    }

    /**
     * Sie steht auf Serverebene, also in **jedem** Server-Block — und nicht in
     * einer `location`.
     *
     * **Der zweite Block ist der, an dem es hängt.** Eine Domain mit Zertifikat
     * hat zwei: Port 80 leitet nach HTTPS, der Inhalt steht im 443er. Fehlte
     * die Wache dort, wäre genau die Domain während der Wartung erreichbar, die
     * ein Besucher tatsächlich aufruft — und der Fall ohne Zertifikat, der
     * leicht zu prüfen ist, wäre richtig.
     *
     * Gemessen wird deshalb die **Zahl** der Blöcke gegen die Zahl der Wachen,
     * in beiden Lagen. Ein Prüfkörper, der nur den einfachen Fall stellt, hätte
     * den anderen nie gesehen.
     */
    public function test_the_guard_stands_in_every_server_block(): void
    {
        $args = $this->forms()[SiteTemplate::FORM_STATIC];

        $ohne = SiteTemplate::render(Site::fromArgs($args));
        $mit = SiteTemplate::render(Site::fromArgs($args + ['certificate' => 'beispiel.de']), $this->store());

        $this->assertSame(1, substr_count($ohne, 'server {'), 'Ohne Zertifikat ist es nicht ein Block — dann misst dieser Fall etwas anderes.');
        $this->assertSame(2, substr_count($mit, 'server {'), 'Mit Zertifikat sind es nicht zwei Blöcke — dann misst dieser Fall etwas anderes.');

        $this->assertSame(1, substr_count($ohne, 'if ($wartung = 1)'), 'Die Wache steht nicht genau einmal je Server-Block.');
        $this->assertSame(2, substr_count($mit, 'if ($wartung = 1)'), 'Der HTTPS-Block trägt die Wache nicht — dort steht der Inhalt.');
    }

    /**
     * Ein Wegwerf-Ablageort mit einer Datei, die für {@see Store::existing()}
     * als vorhanden zählt.
     *
     * Der Inhalt ist bewusst kein gültiges Zertifikat: Gemessen wird hier die
     * **Anzahl der Blöcke**, und ob HSTS dazukäme, entscheidet
     * `PromiseReachTest` an seinem eigenen Prüfkörper.
     */
    private function store(): Store
    {
        $this->root ??= sys_get_temp_dir().'/srvpanel-wartung-'.bin2hex(random_bytes(6));

        if (! is_dir($this->root.'/beispiel.de')) {
            mkdir($this->root.'/beispiel.de', 0o750, true);
        }

        file_put_contents($this->root.'/beispiel.de/fullchain.pem', "-----BEGIN-----\n");
        file_put_contents($this->root.'/beispiel.de/privkey.pem', "-----BEGIN-----\n");

        return new Store($this->root);
    }

    /**
     * Der Block sagt, was er meldet — und nicht, ob Wartung ist.
     *
     * Die Entscheidung trifft nginx bei jeder Anfrage an der Datei. Stünde ein
     * Wahrheitswert in der Vorlage, müsste jede Domain neu geschrieben werden,
     * um zu schalten — und genau das soll A12 nicht.
     */
    public function test_the_template_never_learns_whether_maintenance_is_on(): void
    {
        $args = $this->forms()[SiteTemplate::FORM_STATIC];

        $a = SiteTemplate::render(Site::fromArgs($args));
        $b = SiteTemplate::render(Site::fromArgs($args));

        $this->assertSame($a, $b);

        $mitZeit = SiteTemplate::render(Site::fromArgs($args + ['maintenance_until' => '2026-09-04 16:00']));

        // Die Gegenprobe: Die Zeitangabe ist das Einzige, was sich ändern darf.
        $this->assertNotSame($a, $mitZeit, 'Die Endzeit kommt im Block nicht an.');
        $this->assertStringContainsString('Voraussichtlich ab 2026-09-04 16:00 Uhr', $mitZeit);
        $this->assertStringNotContainsString('Voraussichtlich', $a, 'Ohne Endzeit steht trotzdem eine da.');
    }

    /**
     * Eine Endzeit, die die Form nicht hält, wird abgewiesen.
     *
     * **Das ist die erste Grenze und keine Höflichkeit.** Die Angabe landet als
     * Text *in* einer nginx-Zeichenkette; ein Apostroph darin beendete sie, und
     * aus einer Auskunft würde eine Konfigurationszeile.
     */
    public function test_a_malformed_end_time_is_refused(): void
    {
        foreach (["2026-09-04 16:00'; return 200 'x", '04.09.2026', 'morgen', '2026-09-04T16:00'] as $boese) {
            try {
                Maintenance::until($boese);
                $this->fail('Angenommen: '.$boese);
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull(Maintenance::until(null));
        $this->assertSame('2026-09-04 16:00', Maintenance::until('2026-09-04 16:00'));
    }

    /**
     * Die vier Anweisungen der Wache stehen in der Zusage **jeder** Form.
     *
     * Ohne sie wäre eine Domain ohne Wache eine stille Lücke: `nginx -t` sieht
     * sie nicht (M26), und die Bestandsdiagnose fragt die Zusage.
     */
    public function test_the_guard_is_promised_by_every_form(): void
    {
        $ausDerWache = Statements::heads(Maintenance::nginxGuard(null));

        $this->assertNotSame([], $ausDerWache, 'Die Wache führt keine Anweisung — dann misst dieser Fall nichts.');

        foreach (array_keys(SiteTemplate::PROMISED_BY_FORM) as $form) {
            foreach ($ausDerWache as $anweisung) {
                $this->assertContains(
                    $anweisung,
                    SiteTemplate::promised($form, false),
                    $form.' sagt '.$anweisung.' nicht zu — eine Domain ohne Wache bliebe damit unbemerkt.',
                );
            }
        }
    }
}
