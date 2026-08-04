<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Den FPM-Pool eines Abonnements in einer Version entfernen.
 *
 * **Bleibt danach kein Pool übrig, wird der Masterprozess gestoppt.** Ein
 * PHP-FPM ohne einen einzigen Pool startet nicht — es meldet „no pool
 * defined" und endet. Liesse man die Unit laufen, stünde sie beim nächsten
 * Neustart des Servers in einer Fehlerschleife, und `systemctl` zeigte eine
 * rote Zeile für eine Version, die schlicht niemand mehr benutzt.
 *
 * **Wiederholbar**: Ein Pool, den es nicht mehr gibt, ist der gewünschte
 * Zustand.
 */
final class PhpPoolRemove implements Op
{
    public static function name(): string
    {
        return 'php.pool.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $version = PhpVersions::normalize($args['php_version'] ?? null);

        $file = PhpVersions::poolFile($version, $user);
        $existed = is_file($file);

        if ($existed) {
            unlink($file);
        }

        $remaining = self::pools($version);

        $context->progress(60, $remaining === [] ? 'FPM stoppen' : 'FPM neu laden');

        $unit = PhpVersions::unit($version);

        if ($remaining === []) {
            $context->runner->run('systemctl', ['disable', '--now', $unit], 60);
        } else {
            $context->runner->run('systemctl', ['reload-or-restart', $unit], 60);
        }

        $context->progress(100, 'fertig');

        return [
            'pool' => $file,
            'existed' => $existed,
            'remaining' => count($remaining),
            'php_version' => $version,
        ];
    }

    /**
     * Die Pools dieser Version, die srvpanel gehören.
     *
     * Fremde Pools zählen nicht mit: Wer neben srvpanel einen eigenen Pool
     * angelegt hat, soll seinen Masterprozess behalten. Erkennbar sind die
     * eigenen am Präfix, das {@see PhpVersions::poolFile()} vergibt.
     *
     * @return list<string>
     */
    public static function pools(string $version): array
    {
        $found = glob(PhpVersions::poolDir($version).'/srvpanel-*.conf');

        return $found === false ? [] : array_values($found);
    }
}
