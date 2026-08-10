<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SrvPanel\Agent\Ops\SystemInfo;

/**
 * „Es läuft noch der alte Kernel" wird gemessen — und sonst nichts behauptet.
 *
 * **Warum diese Zeile überhaupt in der Übersicht steht.** Nach einem
 * `apt upgrade` läuft der alte Kernel weiter, bis jemand neu startet. Dem Panel
 * sieht man das sonst nicht an, und genau deshalb zeigt es den Kernel: Die
 * interessante Angabe ist nicht seine Nummer, sondern ob sie noch die richtige
 * ist.
 *
 * **Und der Kernel war schon da.** `SystemInfo` meldet ihn seit P1, der
 * Steuerungscode reicht ihn durch, `Overview.vue` erklärt ihn als Eigenschaft —
 * und keine Zeile hat ihn je gezeigt. Eine Angabe, die den ganzen Weg geht und
 * am Ende nirgends landet, ist Arbeit ohne Wirkung; gefunden beim Bauen dieser
 * Zeile am 10. August 2026.
 *
 * ## Die Regel, die dieser Wächter durchsetzt
 *
 * > **`null` heisst „nicht nachgesehen" und nicht „nein".**
 *
 * Dreimal an einem Tag hat dieser Satz Geld gekostet: bei `handed_over` im
 * Grundzustand von `Pg\Server::describe()`, beim Vorgabewert für das
 * Datenbanksystem und beim Gebietsschema. Hier steht er von Anfang an im Code —
 * ist `/boot` leer oder unlesbar, meldet der Agent `null`, und die Oberfläche
 * schweigt, statt „ist aktuell" zu behaupten.
 */
final class KernelStaleTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative);
    }

    /**
     * Ohne lesbares `/boot` wird nichts behauptet.
     */
    public function test_an_unreadable_boot_says_nothing(): void
    {
        $agent = $this->source('agent/src/Ops/SystemInfo.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$images === false \|\| \$images === \[\]\) \{\s*return null;/',
            $agent,
            'Ein leeres oder unlesbares /boot meldet nicht mehr „nicht nachgesehen". Steht dort '.
            '`false`, behauptet das Panel „der Kernel ist aktuell" für einen Server, auf dem '.
            'niemand nachgesehen hat.',
        );
    }

    /**
     * Gelesen wird `/boot` und kein Programm gerufen.
     *
     * **Die Positivliste des Agenten ist eine Angriffsfläche, und jeder Eintrag
     * darauf will begründet sein.** `uname -a` oder `linux-version` wären je ein
     * Programm mehr für eine Zeile in der Übersicht — und `uname -a` ist
     * ausserdem ein Satz, aus dem man den Kernel erst herausschneidet.
     */
    public function test_it_reads_the_filesystem_and_starts_nothing(): void
    {
        $agent = $this->source('agent/src/Ops/SystemInfo.php');

        $this->assertStringContainsString("glob('/boot/vmlinuz-*')", $agent);

        foreach (['uname', 'linux-version', 'dpkg-query'] as $program) {
            $this->assertStringNotContainsString(
                "'".$program."'",
                $agent,
                sprintf('SystemInfo ruft „%s" auf — für diese Auskunft braucht es kein Programm.', $program),
            );
        }
    }

    /**
     * Der Vergleich ordnet echte Kernelnamen richtig.
     *
     * **Gemessen an dem, was Debian und Ubuntu wirklich vergeben.** Ein
     * Vergleich, der `-51` hinter `-52` einsortiert oder `6.11` vor `6.8`,
     * meldete auf jedem zweiten Server einen Neustart, den es nicht braucht —
     * und ein Melder, der grundlos Alarm gibt, wird bald gelesen wie ein
     * Rauschen.
     */
    public function test_the_comparison_orders_real_kernel_names(): void
    {
        $this->assertTrue(version_compare('6.8.0-52-generic', '6.8.0-51-generic', '>'));
        $this->assertTrue(version_compare('6.11.0-9-generic', '6.8.0-51-generic', '>'));
        $this->assertFalse(version_compare('6.8.0-51-generic', '6.8.0-51-generic', '>'));
        $this->assertTrue(version_compare('6.1.0-28-amd64', '6.1.0-9-amd64', '>'));
    }

    /**
     * Und die Oberfläche liest die dritte Antwort als dritte.
     *
     * `!kernel_stale` wäre für `null` **und** für `false` wahr — die Bedingung
     * sähe richtig aus und behauptete auf jedem Server, auf dem `/boot` nicht
     * lesbar ist, dass ein Neustart aussteht.
     */
    public function test_the_page_distinguishes_unknown_from_current(): void
    {
        $page = $this->source('resources/js/Pages/Overview.vue');

        $this->assertStringContainsString(
            'props.server.kernel_stale === true',
            $page,
            'Der Hinweis hängt nicht mehr am ausdrücklichen Ja.',
        );

        // Die Untergrenze: Ohne sie wäre der Wächter auch mit einer Seite
        // einverstanden, die den Kernel gar nicht mehr zeigt — und genau so
        // stand es bis zum 10. August 2026 da.
        $this->assertStringContainsString('kernelText', $page);
    }

    /**
     * Und der Steuerungscode ebnet sie nicht ein.
     */
    public function test_the_controller_passes_the_third_value_through(): void
    {
        $this->assertStringContainsString(
            "is_bool(\$info['kernel_stale'] ?? null) ? \$info['kernel_stale'] : null",
            $this->source('app/Http/Controllers/OverviewController.php'),
            'Der Steuerungscode macht aus drei Werten zwei — dann sieht die Seite nie ein '.
            '`null`, und die Unterscheidung dort ist wirkungslos.',
        );
    }

    /**
     * Und die Datei, die hier als Text gelesen wird, ist die von {@see SystemInfo}.
     */
    public function test_the_guard_reads_the_file_of_the_class_it_names(): void
    {
        $this->assertSame(
            realpath(dirname(__DIR__, 2).'/agent/src/Ops/SystemInfo.php'),
            (new ReflectionClass(SystemInfo::class))->getFileName(),
        );
    }
}
