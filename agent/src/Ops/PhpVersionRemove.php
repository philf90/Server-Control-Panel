<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Eine PHP-Version wieder entfernen.
 *
 * **Solange ein Abonnement einen Pool in dieser Version hat, wird abgewiesen.**
 * Die Alternative wäre, die Pools mitzunehmen — und damit ohne Rückfrage
 * fremde Websites stillzulegen. Welche Domains betroffen wären, weiss das
 * Panel; es zeigt sie an, und der Betreiber stellt sie um. Erst dann geht die
 * Version.
 *
 * **`remove` und nicht `purge`.** Die Konfiguration unter `/etc/php/<version>`
 * bleibt liegen. Wer die Version später wieder installiert, findet seine
 * Anpassungen vor; und `purge` nähme auf manchen Systemen `php-common` mit,
 * an dem die übrigen Versionen hängen.
 */
final class PhpVersionRemove implements Op
{
    public static function name(): string
    {
        return 'php.version.remove';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $version = PhpVersions::normalize($args['php_version'] ?? null);

        if (! PhpVersions::installed($version)) {
            return ['php_version' => $version, 'removed' => false, 'already' => true];
        }

        $pools = PhpPoolRemove::pools($version);

        if ($pools !== []) {
            throw AgentException::denied(sprintf(
                'PHP %s wird noch von %d Abonnement(en) benutzt. Erst umstellen, dann entfernen.',
                $version,
                count($pools),
            ));
        }

        $context->progress(20, 'FPM stoppen');
        $context->runner->run('systemctl', ['disable', '--now', PhpVersions::unit($version)], 60);

        $context->progress(40, 'Pakete entfernen');
        $remove = $context->stream(
            'apt-get',
            array_merge(['remove', '-y'], PhpVersions::packages($version)),
            600,
        );

        if (! $remove->successful()) {
            throw AgentException::execFailed('Das Entfernen ist fehlgeschlagen: '.$remove->message());
        }

        $context->progress(100, 'fertig');

        return [
            'php_version' => $version,
            'removed' => true,
            'already' => false,
            'packages' => PhpVersions::packages($version),
        ];
    }
}
