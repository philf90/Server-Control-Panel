<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\PhpSettings;
use SrvPanel\Agent\PoolTemplate;

/**
 * Die Abschottung zweier Abonnements voneinander — an der Vorlage geprüft.
 *
 * **Das ist das Abnahmekriterium von P3**: „ein Skript im einen Abo kann
 * nachweislich nicht auf Dateien des anderen zugreifen". Nachgewiesen wird das
 * am Ende auf einem echten Server (`srvpanel acceptance`); hier steht die
 * Vorbedingung dafür, und sie ist eine Eigenschaft von drei Zeilen Text.
 *
 * Der Test prüft ausdrücklich auch die **Art** der Zeile: `php_admin_value`
 * und nicht `php_value`. Der Unterschied ist die ganze Abschottung — ein
 * `php_value[open_basedir]` liesse sich mit einem `ini_set()` im Skript
 * aushebeln, und die Vorlage sähe fast genauso aus.
 */
final class PhpIsolationTest extends TestCase
{
    private function pool(string $subscription = 'beispiel.de', string $user = 'p1001'): string
    {
        return PoolTemplate::render($subscription, $user, '8.4', 10);
    }

    public function test_the_pool_runs_as_the_subscriptions_user(): void
    {
        $pool = $this->pool();

        $this->assertStringContainsString('[p1001]', $pool);
        $this->assertStringContainsString('user = p1001', $pool);
        $this->assertStringContainsString('group = p1001', $pool);

        // Niemals www-data: Ein Pool als www-data liefe unter demselben
        // Benutzer wie jeder andere und läse dessen Dateien.
        $this->assertStringNotContainsString('user = www-data', $pool);
    }

    public function test_open_basedir_is_admin_and_covers_only_the_own_root(): void
    {
        $pool = $this->pool();

        $this->assertStringContainsString(
            'php_admin_value[open_basedir] = /var/www/vhosts/beispiel.de/:/usr/share/php/',
            $pool,
        );

        // Als php_value wäre es eine Empfehlung.
        $this->assertStringNotContainsString('php_value[open_basedir]', $pool);

        // Kein geteiltes /tmp: Dort begegnen sich sonst die hochgeladenen
        // Dateien und Sitzungskennungen zweier Abonnements.
        $this->assertStringNotContainsString(':/tmp', $pool);
        $this->assertStringNotContainsString('/var/www/vhosts/:', $pool);
    }

    public function test_tmp_and_sessions_are_inside_the_subscription(): void
    {
        $pool = $this->pool();

        foreach (['upload_tmp_dir', 'sys_temp_dir', 'session.save_path'] as $key) {
            $this->assertStringContainsString(
                sprintf('php_admin_value[%s] = /var/www/vhosts/beispiel.de/tmp', $key),
                $pool,
            );
        }
    }

    public function test_the_ways_to_start_a_process_are_closed(): void
    {
        $pool = $this->pool();

        foreach (['exec', 'shell_exec', 'passthru', 'system', 'proc_open', 'popen', 'pcntl_exec', 'dl'] as $function) {
            $this->assertContains($function, PoolTemplate::DISABLED_FUNCTIONS);
        }

        $this->assertStringContainsString('php_admin_value[disable_functions] =', $pool);

        // `mail` bleibt: Ohne Mailversand aus PHP ist ein Hosting-Paket keines.
        $this->assertNotContains('mail', PoolTemplate::DISABLED_FUNCTIONS);
    }

    public function test_only_php_is_executed(): void
    {
        // Die Voreinstellung erlaubt zusätzlich .phar — ein Archiv, das PHP
        // ausführt, und damit ein zweiter Weg an jeder Prüfung vorbei, die auf
        // die Endung .php sieht.
        $this->assertStringContainsString('security.limit_extensions = .php', $this->pool());
    }

    public function test_the_socket_is_readable_for_nginx_and_nobody_else(): void
    {
        $pool = $this->pool();

        $this->assertStringContainsString('listen = /run/php/srvpanel-p1001-8.4.sock', $pool);
        $this->assertStringContainsString('listen.owner = www-data', $pool);
        $this->assertStringContainsString('listen.mode = 0660', $pool);
    }

    public function test_two_subscriptions_share_no_path(): void
    {
        $one = PoolTemplate::render('eins.de', 'p1001', '8.4', 10);
        $two = PoolTemplate::render('zwei.de', 'p1002', '8.4', 10);

        $this->assertStringContainsString('/var/www/vhosts/eins.de/', $one);
        $this->assertStringNotContainsString('/var/www/vhosts/zwei.de', $one);

        $this->assertStringContainsString('/var/www/vhosts/zwei.de/', $two);
        $this->assertStringNotContainsString('/var/www/vhosts/eins.de', $two);

        // Auch die Sockel dürfen sich nicht überschneiden.
        $this->assertStringContainsString('srvpanel-p1001-8.4.sock', $one);
        $this->assertStringContainsString('srvpanel-p1002-8.4.sock', $two);
    }

    public function test_the_process_limit_is_the_quota(): void
    {
        $this->assertStringContainsString('pm.max_children = 7', PoolTemplate::render('a.de', 'p1001', '8.4', 7));
    }

    /**
     * Was eine Domain übersteuern darf, rührt die Abschottung nicht an.
     *
     * Das ist die Gegenrichtung zu allem darüber: Die Liste der erlaubten
     * Einstellungen enthält nichts, was im Pool als `php_admin_value` steht —
     * sonst stünde im Formular ein Feld, das entweder nichts tut oder die
     * Abschottung aufmacht.
     */
    public function test_no_domain_setting_touches_the_isolation(): void
    {
        $protected = [
            'open_basedir', 'disable_functions', 'upload_tmp_dir',
            'sys_temp_dir', 'session.save_path', 'error_log',
        ];

        foreach ($protected as $key) {
            $this->assertArrayNotHasKey(
                $key,
                PhpSettings::ALLOWED,
                sprintf('%s steht im Pool als php_admin_value und darf keine Domaineinstellung sein.', $key),
            );
        }
    }
}
