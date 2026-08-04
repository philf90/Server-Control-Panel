<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Welche PHP-Versionen liegen auf diesem Server?
 *
 * **Die Antwort ist der Unterschied zwischen „der Plan erlaubt es" und „der
 * Kunde kann es wählen".** Das Panel rechnet aus drei Mengen: dem Katalog
 * (was das Panel kennt), dem Plan (was der Vertrag hergibt) und dieser hier
 * (was installiert ist). Nur der Schnitt steht im Auswahlfeld; eine Version,
 * die der Plan erlaubt und die hier fehlt, erscheint abgeblendet mit dem
 * Grund — der Kunde sieht damit, dass die Lücke am Server liegt und nicht an
 * seinem Vertrag.
 *
 * Fragt jemand ohne Zutun des Betreibers nach — das Auswahlfeld einer Domain
 * etwa —, kommt die Antwort aus dem Zwischenspeicher des Panels. Diese
 * Operation ist die Quelle, die ihn füllt, und läuft auf der Betreiberseite:
 * beim Öffnen von `/settings/php` und nach jeder Installation.
 */
final class PhpVersionList implements Op
{
    public static function name(): string
    {
        return 'php.versions';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $versions = [];

        foreach (PhpVersions::CATALOG as $version) {
            $installed = PhpVersions::installed($version);

            $versions[] = [
                'version' => $version,
                'installed' => $installed,
                'unit' => PhpVersions::unit($version),
                'active' => $installed && $this->active($context, PhpVersions::unit($version)),
                'pools' => $installed ? count(PhpPoolRemove::pools($version)) : 0,
                'release' => $installed ? $this->release($context, $version) : null,
            ];
        }

        // `available` steht neben `versions`, weil das Panel genau diese
        // Liste in seinen Zwischenspeicher legt. Sie aus `versions`
        // herauszurechnen wäre dieselbe Aussage an einer zweiten Stelle.
        return ['versions' => $versions, 'available' => PhpVersions::available()];
    }

    private function active(Context $context, string $unit): bool
    {
        return trim($context->runner->run('systemctl', ['is-active', $unit], 15)->stdout) === 'active';
    }

    /**
     * Die genaue Version, wie sie das Paket mitbringt.
     *
     * `php-fpm8.4 -v` schreibt „PHP 8.4.13 (fpm-fcgi) …". Der Betreiber will
     * das sehen: „8.4" sagt nicht, ob die Sicherheitsaktualisierung von letzter
     * Woche schon drauf ist.
     */
    private function release(Context $context, string $version): ?string
    {
        $result = $context->runner->run(PhpVersions::program($version), ['-v'], 15);

        if (preg_match('/^PHP (\d+\.\d+\.\d+)/m', $result->stdout, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
