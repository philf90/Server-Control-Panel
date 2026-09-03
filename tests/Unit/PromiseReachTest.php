<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\PoolTemplate;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Die Zusage einer Vorlage ist genau das, was jede ihrer Formen ausgibt.
 *
 * ## Warum in beide Richtungen
 *
 * **Zu gross**, und die Diagnose meldet jede Nacht jede heile Domain — die
 * Falle aus `docs/98 §4`, die den Lauf in zwei Wochen unlesbar macht. **Zu
 * klein**, und ein Verlust bleibt stumm, weil niemand ihn zugesagt hat. Deshalb
 * wird die Liste nicht gegen „enthält" gehalten, sondern gegen die
 * **Schnittmenge** aller Formen: Was in jeder Form steht, ist zugesagt — nicht
 * mehr und nicht weniger. Wer der Vorlage eine Anweisung gibt, die überall
 * steht, trägt sie in `PROMISED` nach oder erklärt, warum nicht.
 *
 * > **Eine Zusage, die kleiner ist als die Vorlage, meldet nichts; eine, die
 * > grösser ist, meldet alles.**
 *
 * Die Formen sind die aus `SiteTemplateTest`: ausliefernd mit PHP, ohne PHP,
 * gesperrt, weiterleitend — und mit Zertifikat, weil der 443er Block ein
 * eigener `server` ist.
 */
final class PromiseReachTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            foreach (glob($this->root.'/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->root.'/*') ?: [] as $directory) {
                @rmdir($directory);
            }
            @rmdir($this->root);
            $this->root = null;
        }

        parent::tearDown();
    }

    /** @return array<string, string> je Form der gerenderte Text */
    private function forms(): array
    {
        $basis = [
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
            'php_version' => '8.4',
            'certificate' => null,
        ];

        $this->root = sys_get_temp_dir().'/srvpanel-promise-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/beispiel.de', 0o750, true);
        file_put_contents($this->root.'/beispiel.de/fullchain.pem', "-----BEGIN-----\n");
        file_put_contents($this->root.'/beispiel.de/privkey.pem', "-----BEGIN-----\n");
        $store = new Store($this->root);

        return [
            'ausliefernd mit PHP' => SiteTemplate::render(Site::fromArgs($basis)),
            'ausliefernd ohne PHP' => SiteTemplate::render(Site::fromArgs(['php_version' => null] + $basis)),
            'gesperrt' => SiteTemplate::render(Site::fromArgs(['suspended' => true] + $basis)),
            'weiterleitend' => SiteTemplate::render(Site::fromArgs(['redirect_target' => 'https://ziel.de/'] + $basis)),
            'mit Zertifikat' => SiteTemplate::render(Site::fromArgs(['certificate' => 'beispiel.de'] + $basis), $store),
        ];
    }

    /** @return list<string> */
    private function heads(string $rendered): array
    {
        $heads = [];

        foreach (Statements::nginx($rendered) as $words) {
            $heads[$words[0]] = true;
        }

        return array_keys($heads);
    }

    public function test_the_site_promise_is_exactly_what_every_form_emits(): void
    {
        $forms = $this->forms();
        $this->assertGreaterThanOrEqual(5, count($forms));

        $common = null;

        foreach ($forms as $name => $rendered) {
            $heads = $this->heads($rendered);
            $this->assertNotSame([], $heads, $name.': keine Anweisung gefunden — der Schnitt misst nichts.');
            $common = $common === null ? $heads : array_values(array_intersect($common, $heads));
        }

        $promised = SiteTemplate::PROMISED;
        sort($promised);
        $common = $common ?? [];
        sort($common);

        $this->assertSame($common, $promised, sprintf(
            "SiteTemplate::PROMISED ist nicht die Schnittmenge aller Formen.\n  zugesagt, aber nicht überall: %s\n  überall, aber nicht zugesagt: %s",
            implode(', ', array_diff($promised, $common)) ?: '–',
            implode(', ', array_diff($common, $promised)) ?: '–',
        ));
    }

    /** Und jede Form verliert gegen ihre eigene Zusage nichts — sonst meldete die Diagnose jede Nacht. */
    public function test_no_form_loses_its_own_promise(): void
    {
        foreach ($this->forms() as $name => $rendered) {
            $this->assertSame([], Statements::lostInNginx($rendered, SiteTemplate::PROMISED), $name);
        }
    }

    public function test_the_pool_promise_is_in_the_template(): void
    {
        $rendered = PoolTemplate::render('beispiel.de', 'p1001', '8.4', 5);
        $keys = Statements::ini($rendered);

        $this->assertGreaterThanOrEqual(12, count($keys), 'Zu wenige Schlüssel — der Schnitt misst nichts.');

        foreach (PoolTemplate::PROMISED as $key) {
            $this->assertContains($key, $keys, sprintf('%s ist zugesagt und steht nicht in der Vorlage — die Diagnose meldete jeden Pool.', $key));
        }

        $this->assertSame([], Statements::lostInIni($rendered, PoolTemplate::PROMISED));
    }

    /** Die Abschottung ist zugesagt — nicht nur irgendwelche Schlüssel. */
    public function test_the_pool_promise_carries_the_isolation(): void
    {
        foreach (['php_admin_value[open_basedir]', 'php_admin_value[disable_functions]', 'security.limit_extensions', 'user', 'listen.mode'] as $key) {
            $this->assertContains($key, PoolTemplate::PROMISED, $key.' ist nicht zugesagt — ein Pool, der ihn verliert, fiele nicht auf.');
        }
    }
}
