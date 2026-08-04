<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Plans\Quota;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\PhpSettings;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\PoolTemplate;
use SrvPanel\Agent\Runner;

/**
 * Die drei Zusagen aus `docs/23 §7` — mechanisch geprüft.
 *
 * Dort steht: „Für jede Version darin muss es eine FPM-Vorlage, einen
 * Paketnamen und einen Handler geben. Eine Version hinzunehmen heißt, diese
 * drei Dinge mitzuliefern." Das war bis hierher ein Satz in einem Dokument —
 * also genau die Sorte Regel, die dieses Projekt schon mehrfach eingeholt hat:
 * eine Zeichenkette, die auf etwas verweist, ohne dass etwas den Bezug prüft.
 *
 * Wer `8.0` in den Katalog schreibt, bekommt ab jetzt einen roten Lauf und
 * keinen Kunden, dessen Website nichts ausliefert.
 */
final class PhpVersionCatalogTest extends TestCase
{
    public function test_the_catalog_is_not_empty(): void
    {
        $this->assertNotSame([], PhpVersions::CATALOG);
    }

    public function test_every_version_has_template_package_and_handler(): void
    {
        foreach (PhpVersions::CATALOG as $version) {
            // 1. Vorlage — sie muss sich erzeugen lassen und den Sockel dieser
            //    Version tragen.
            $pool = PoolTemplate::render('beispiel.de', 'p1001', $version, 5);
            $this->assertStringContainsString('srvpanel-p1001-'.$version.'.sock', $pool);

            // 2. Paketnamen — gebaut aus zwei Positivlisten, nie übergeben.
            $packages = PhpVersions::packages($version);
            $this->assertContains('php'.$version.'-fpm', $packages);
            $this->assertSame(count(PhpVersions::EXTENSIONS), count($packages));

            // 3. Handler — der Pfad, und die Zeile in der Positivliste des
            //    Runners. Ohne sie liesse sich der Pool nie prüfen: Der Agent
            //    startet kein Programm, das dort nicht steht.
            $this->assertSame('/usr/sbin/php-fpm'.$version, PhpVersions::binary($version));
            $this->assertTrue(
                Runner::knows(PhpVersions::program($version)),
                sprintf('php-fpm%s fehlt in der Positivliste des Runners.', $version),
            );

            $this->assertSame('php'.$version.'-fpm.service', PhpVersions::unit($version));
            $this->assertSame('/etc/php/'.$version.'/fpm/pool.d', PhpVersions::poolDir($version));
        }
    }

    /**
     * Panel und Agent nennen dieselben Versionen.
     *
     * Sie **sind** dieselbe Liste — `Quota::PHP_VERSIONS` zeigt seit P3 auf den
     * Katalog des Agenten. Der Test steht trotzdem da: Er ist die Prüfung, die
     * anschlägt, wenn jemand die Liste im Panel wieder ausschreibt, weil das
     * bequemer aussieht.
     */
    public function test_panel_and_agent_agree(): void
    {
        $this->assertSame(PhpVersions::CATALOG, Quota::PHP_VERSIONS);
    }

    public function test_a_version_outside_the_catalog_is_rejected(): void
    {
        foreach (['8.0', '9.0', '8.4.1', '../../etc', 'latest', ''] as $version) {
            try {
                PhpVersions::normalize($version);
                $this->fail(sprintf('%s hätte abgewiesen werden müssen.', $version));
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Der Sockelpfad bleibt unter der Grenze des Betriebssystems.
     *
     * Ein Unix-Sockel darf 108 Byte lang sein. Stünde der Name des
     * Abonnements darin — bis zu 63 Zeichen —, wäre das eine Grenze, die erst
     * beim langen Namen zubeisst, und dann mit einer Meldung über einen
     * abgeschnittenen Pfad.
     */
    public function test_the_socket_path_stays_short(): void
    {
        foreach (PhpVersions::CATALOG as $version) {
            $this->assertLessThan(108, strlen(PhpVersions::socket($version, 'p999999999')));
        }
    }

    /** Kein Wert einer Domaineinstellung kann die Zeichenkette verlassen, in der er steht. */
    public function test_no_setting_value_can_escape_its_line(): void
    {
        $attacks = [
            'memory_limit' => ['256M"', "256M\nopen_basedir=/", '256M;', '$(reboot)', '256'],
            'display_errors' => ['on;', "on\ndisable_functions=", 'yes'],
            'date.timezone' => ['Europe/Berlin"', "Europe/Berlin\n", 'Europe Berlin'],
        ];

        foreach ($attacks as $key => $values) {
            foreach ($values as $value) {
                try {
                    PhpSettings::check([$key => $value]);
                    $this->fail(sprintf('%s=%s hätte abgewiesen werden müssen.', $key, $value));
                } catch (AgentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        // Und die Gegenprobe: Die gültigen Werte gehen durch.
        $this->assertSame(
            ['memory_limit' => '256M', 'display_errors' => 'on', 'date.timezone' => 'Europe/Berlin'],
            PhpSettings::check([
                'memory_limit' => '256M',
                'display_errors' => 'on',
                'date.timezone' => 'Europe/Berlin',
            ]),
        );
    }

    public function test_an_unknown_setting_is_rejected(): void
    {
        $this->expectException(AgentException::class);

        PhpSettings::check(['open_basedir' => '/']);
    }
}
