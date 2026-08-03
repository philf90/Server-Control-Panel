<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SrvPanel\Agent\Mounts;
use SrvPanel\Agent\Ops\SubscriptionQuota;
use SrvPanel\Agent\Ops\SubscriptionUsage;

/**
 * Die Messung des belegten Speichers — die Teile, die rechnen und auswählen.
 *
 * **Warum gerade diese beiden.** `repquota` aufzurufen braucht ein
 * Dateisystem mit Quota; das lässt sich hier nicht herstellen und wäre auch
 * nicht die interessante Stelle. Schiefgehen kann zweierlei: das Zerlegen der
 * Ausgabe (falsche Spalte, falsche Einheit, Kopfzeile als Benutzer gelesen)
 * und die Auswahl des Geräts. Beides ist reine Rechnung und damit prüfbar.
 */
final class SubscriptionUsageTest extends TestCase
{
    /** @return array<string, array{used_mb: int, limit_mb: int}> */
    private function parse(string $csv): array
    {
        $method = new ReflectionMethod(SubscriptionUsage::class, 'parse');

        /** @var array<string, array{used_mb: int, limit_mb: int}> $users */
        $users = $method->invoke(new SubscriptionUsage, $csv);

        return $users;
    }

    public function test_blocks_are_kibibytes_and_the_result_is_megabytes(): void
    {
        // 102400 KiB = 100 MB belegt, harte Grenze 5242880 KiB = 5120 MB.
        // repquota rechnet immer in 1-KiB-Blöcken, unabhängig von der
        // Blockgrösse des Dateisystems — wer das für 4-KiB-Blöcke hält,
        // meldet den vierfachen Verbrauch.
        $users = $this->parse("Benutzer,ok,ok,102400,5242880,5242880,,131,0,0,\n");

        $this->assertSame([], $users, 'Die Kopfzeile ist kein Abonnement.');

        $users = $this->parse("p1000,ok,ok,102400,5242880,5242880,,131,0,0,\n");

        $this->assertSame(['p1000' => ['used_mb' => 100, 'limit_mb' => 5120]], $users);
    }

    public function test_only_the_users_of_the_panel_are_reported(): void
    {
        /*
         * repquota gibt jeden Benutzer des Dateisystems aus. Hier steht die
         * Schranke, die verhindert, dass das Panel die Benutzerliste des
         * Servers zu sehen bekommt — eine Auskunft, die niemand bestellt hat.
         */
        $users = $this->parse(implode("\n", [
            'root,ok,ok,4194304,0,0,,900,0,0,',
            'www-data,ok,ok,1024,0,0,,12,0,0,',
            'p1000,ok,ok,2048,0,1048576,,7,0,0,',
            'p999,ok,ok,2048,0,0,,7,0,0,',
            'p1000000000,ok,ok,2048,0,0,,7,0,0,',
        ]));

        // `p999` hat drei Ziffern, `p1000000000` zehn — beide fallen aus der
        // Form, die der Agent beim Anlegen erzwingt.
        $this->assertSame(['p1000'], array_keys($users));
    }

    public function test_a_line_without_enough_fields_is_skipped(): void
    {
        // Die letzte Zeile einer Ausgabe ist regelmässig leer, und eine
        // abgeschnittene Zeile hat weniger Felder. Beides darf keinen Wert
        // aus einer falschen Spalte ergeben.
        $users = $this->parse("\np1000,ok\np1001,ok,ok,2048,0,2097152,,7,0,0,\n\n");

        $this->assertSame(['p1001' => ['used_mb' => 2, 'limit_mb' => 2048]], $users);
    }

    public function test_a_fraction_of_a_megabyte_is_rounded_down(): void
    {
        // 512 KiB sind ein halbes MB. Aufgerundet stünde bei einem leeren
        // Abonnement „1 MB belegt" — und dann sähe „belegt" nie nach null aus.
        $users = $this->parse("p1000,ok,ok,512,0,0,,1,0,0,\n");

        $this->assertSame(0, $users['p1000']['used_mb']);
    }

    public function test_the_longest_mount_point_wins(): void
    {
        /*
         * Der Fall, für den die Regel da ist: ein eigener Datenträger für
         * /var/www/vhosts. Ohne „am längsten gewinnt" fände die Quota `/`, und
         * dann setzte das Panel die Grenze auf dem einen Gerät und läse den
         * Verbrauch auf dem anderen — mit dem Ergebnis, dass der Verbrauch
         * dauerhaft auf null stünde und nichts daran nach einem Fehler aussähe.
         */
        $mounts = [
            '/dev/sda1 / ext4 rw,relatime 0 0',
            'tmpfs /tmp tmpfs rw 0 0',
            '/dev/sdb1 /var/www ext4 rw 0 0',
            '/dev/sdc1 /var/www/vhosts ext4 rw,usrquota 0 0',
        ];

        $this->assertSame('/dev/sdc1', Mounts::pick($mounts, '/var/www/vhosts'));
        $this->assertSame('/dev/sdb1', Mounts::pick($mounts, '/var/www/anderes'));
        $this->assertSame('/dev/sda1', Mounts::pick($mounts, '/etc'));
    }

    public function test_a_mount_point_is_not_matched_by_its_prefix(): void
    {
        // `/var/wwwx` fängt mit `/var/www` an und liegt trotzdem nicht darin.
        $mounts = [
            '/dev/sda1 / ext4 rw 0 0',
            '/dev/sdb1 /var/www ext4 rw 0 0',
        ];

        $this->assertSame('/dev/sda1', Mounts::pick($mounts, '/var/wwwx'));
    }

    public function test_pseudo_filesystems_are_ignored(): void
    {
        // proc, sysfs und cgroup2 stehen in derselben Liste und tragen keine
        // Benutzerquota. Ihr „Gerät" ist kein Pfad.
        $mounts = [
            'proc /proc proc rw 0 0',
            'cgroup2 /sys/fs/cgroup cgroup2 rw 0 0',
            '/dev/sda1 / ext4 rw 0 0',
        ];

        $this->assertSame('/dev/sda1', Mounts::pick($mounts, '/proc/self'));
    }

    /**
     * `subscription.quota` darf eine Sperre nicht aufheben.
     *
     * **Das ist der Grund, warum es diese Operation überhaupt gibt.** Der
     * bequeme Weg wäre gewesen, für ein geändertes Kontingent einfach
     * `subscription.provision` noch einmal zu rufen — sie ist wiederholbar.
     * Sie rückt dabei aber die Rechte der Chroot-Wurzel auf `0755` zurecht,
     * und genau dieses Bit nimmt `subscription.suspend` weg. Ein gesperrtes
     * Abonnement wäre nach einer Kontingentänderung wieder erreichbar gewesen,
     * und im Panel hätte weiter „gesperrt" gestanden: Die Sperre wäre nicht
     * aufgehoben, sondern unsichtbar geworden.
     *
     * Geprüft wird am Quelltext und nicht am Verhalten, weil das Verhalten
     * root und ein Dateisystem mit Quota bräuchte. Was hier zählt, ist eine
     * Aussage über den Umfang: Diese Operation fasst nur `setquota` an.
     */
    public function test_setting_a_quota_touches_neither_directory_nor_account(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/SubscriptionQuota.php'
        );

        // Kommentare raus — der Text darüber erklärt gerade diese Wörter.
        $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $source);

        foreach (['chmod', 'chown', 'mkdir', 'useradd', 'usermod', 'groupadd', 'rm'] as $verboten) {
            $this->assertSame(
                0,
                preg_match('/\b'.preg_quote($verboten, '/').'\b/', $code),
                sprintf(
                    'subscription.quota fasst „%s" an. Sie setzt eine Zahl — alles Weitere gehört zu '.
                    'subscription.provision, und das Zugriffsbit der Wurzel trägt die Sperre.',
                    $verboten,
                ),
            );
        }
    }

    public function test_measuring_changes_nothing_and_setting_a_quota_does(): void
    {
        // Die Kennzeichnung steuert die Protokollierung im Agenten. Eine
        // Messung, die als verändernd geführt wird, füllt das Journal alle
        // fünfzehn Minuten mit einer Änderung, die es nicht gab.
        $this->assertFalse(SubscriptionUsage::mutating());
        $this->assertTrue(SubscriptionQuota::mutating());

        $this->assertSame('subscription.usage', SubscriptionUsage::name());
        $this->assertSame('subscription.quota', SubscriptionQuota::name());
    }
}
