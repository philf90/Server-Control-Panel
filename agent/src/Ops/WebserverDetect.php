<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Welcher Webserver liegt auf diesem Rechner — und darf srvpanel arbeiten?
 *
 * §9 P3, erster Spiegelstrich: „nginx als verwalteter Webserver; ein
 * bestehender Apache wird erkannt und nicht angefasst (Betrieb dann
 * verweigert, mit Erklärung)."
 *
 * **Verweigern ist hier die freundliche Antwort.** Ein Apache, der läuft,
 * gehört jemandem — er liefert Websites aus, die es vor srvpanel gab. nginx
 * daneben zu starten scheitert am belegten Port 80; ihn zu übernehmen hiesse,
 * fremde vhosts stillzulegen, die niemand in diesem Panel wiederfindet. Beides
 * ist schlechter als eine klare Auskunft: „Auf diesem Server läuft Apache.
 * srvpanel verwaltet ausschliesslich nginx."
 *
 * **Diese Operation ändert nichts.** Sie schaut nach und sagt, was sie sieht.
 * Wer daraus eine Folge zieht, ist das Panel — und die Folge steht in der
 * Antwort, nicht im Ermessen des Aufrufers.
 */
final class WebserverDetect implements Op
{
    /**
     * Die Programme, an denen ein anderer Webserver zu erkennen ist.
     *
     * `httpd` steht daneben, weil Apache auf RHEL-Abkömmlingen so heisst.
     * Diese sind keine Zielplattform — aber jemand wird es versuchen, und dann
     * ist eine Erklärung besser als ein belegter Port.
     *
     * @var array<string, list<string>>
     */
    private const FOREIGN = [
        'apache' => ['/usr/sbin/apache2', '/usr/sbin/httpd'],
        'lighttpd' => ['/usr/sbin/lighttpd'],
        'caddy' => ['/usr/bin/caddy'],
    ];

    /** @var array<string, list<string>> */
    private const UNITS = [
        'apache' => ['apache2.service', 'httpd.service'],
        'lighttpd' => ['lighttpd.service'],
        'caddy' => ['caddy.service'],
    ];

    public static function name(): string
    {
        return 'webserver.detect';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $nginx = is_executable('/usr/sbin/nginx');
        $foreign = [];

        foreach (self::FOREIGN as $kind => $paths) {
            $installed = false;

            foreach ($paths as $path) {
                if (is_executable($path)) {
                    $installed = true;
                    break;
                }
            }

            if (! $installed) {
                continue;
            }

            $active = $this->anyActive($context, self::UNITS[$kind]);

            $foreign[] = ['kind' => $kind, 'active' => $active];
        }

        // **Nur ein *laufender* fremder Webserver verweigert den Betrieb.**
        // Installiert und gestoppt ist etwas anderes: Auf manchen Systemen
        // liegt Apache als Abhängigkeit eines Pakets herum, ohne je gestartet
        // zu werden. Wer deswegen den Dienst verweigerte, verweigerte ihn auf
        // einem Server, auf dem nichts im Weg ist.
        $blocking = array_values(array_filter($foreign, static fn (array $one): bool => $one['active'] === true));

        return [
            'nginx' => $nginx,
            'nginx_active' => $nginx && $this->anyActive($context, ['nginx.service']),
            'foreign' => $foreign,
            'usable' => $nginx && $blocking === [],
            'reason' => $this->reason($nginx, $blocking),
        ];
    }

    /** @param list<string> $units */
    private function anyActive(Context $context, array $units): bool
    {
        foreach ($units as $unit) {
            $result = $context->runner->run('systemctl', ['is-active', $unit], 15);

            // `is-active` endet mit 3, wenn die Unit nicht läuft — das ist
            // kein Fehler, sondern die Antwort. Deshalb wird die Ausgabe
            // gelesen und nicht der Rückgabewert.
            if (trim($result->stdout) === 'active') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{kind: string, active: bool}> $blocking */
    private function reason(bool $nginx, array $blocking): ?string
    {
        if ($blocking !== []) {
            $names = implode(', ', array_map(static fn (array $one): string => $one['kind'], $blocking));

            return sprintf(
                'Auf diesem Server läuft %s. srvpanel verwaltet ausschliesslich nginx und fasst einen '.
                'fremden Webserver nicht an — seine Websites wären sonst ohne Ankündigung abgeschaltet.',
                $names,
            );
        }

        if (! $nginx) {
            return 'nginx ist nicht installiert.';
        }

        return null;
    }
}
