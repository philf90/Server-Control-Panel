<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;

/**
 * Eine PHP-Version installieren.
 *
 * **Kein Freitext erreicht apt.** Der Paketname entsteht aus zwei
 * Positivlisten — der Version aus {@see PhpVersions::CATALOG} und der
 * Erweiterung aus {@see PhpVersions::EXTENSIONS}. `php8.2-mysql` wird gebaut,
 * nicht entgegengenommen. Das ist dieselbe Regel wie überall im Agenten, und
 * sie ist hier besonders wichtig: `apt-get install` mit einem Namen aus einer
 * Anfrage wäre eine Fernsteuerung für beliebige Pakete.
 *
 * **Der Standard-Pool der Distribution wird abgeschaltet.** `phpX.Y-fpm`
 * bringt `www.conf` mit: ein geteilter Pool, der als `www-data` läuft, ohne
 * `open_basedir` und ohne `disable_functions`. Er ist genau das Loch, das P3
 * zumacht — ein Skript darin läge ausserhalb jeder Abschottung. Er wird
 * umbenannt und nicht gelöscht: Wer nachsehen will, was die Distribution
 * vorgesehen hatte, findet es neben der Datei.
 *
 * **Die Unit bleibt danach stehen, wenn es keinen Pool gibt.** Ein PHP-FPM
 * ohne Pool startet nicht — es meldet „no pool defined". Sie zu starten,
 * bevor das erste Abonnement einen Pool hat, ergäbe eine rote Zeile in
 * `systemctl`, die nach einem Fehler aussieht und keiner ist. Der erste
 * `php.pool.apply` startet sie.
 */
final class PhpVersionInstall implements Op
{
    /** Die Endung, unter der der Pool der Distribution weiterlebt. */
    public const DISABLED_SUFFIX = '.srvpanel-disabled';

    public static function name(): string
    {
        return 'php.version.install';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $version = PhpVersions::normalize($args['php_version'] ?? null);

        if (PhpVersions::installed($version)) {
            // Wiederholbar: Der gewünschte Zustand ist schon da. Der Pool der
            // Distribution wird trotzdem geprüft — er kann aus einer
            // Installation von Hand stammen.
            return [
                'php_version' => $version,
                'installed' => true,
                'already' => true,
                'distribution_pool' => $this->disableDistributionPool($version),
                'packages' => [],
                'available' => PhpVersions::available(),
            ];
        }

        $packages = PhpVersions::packages($version);

        $context->progress(10, 'Paketlisten auffrischen');
        $update = $context->stream('apt-get', ['update', '-qq'], 300);

        if (! $update->successful()) {
            throw AgentException::execFailed('apt-get update ist fehlgeschlagen: '.$update->message());
        }

        $context->progress(30, 'Pakete installieren');
        $install = $context->stream(
            'apt-get',
            array_merge(['install', '-y', '--no-install-recommends'], $packages),
            900,
        );

        if (! $install->successful()) {
            throw AgentException::execFailed(
                'Die Installation ist fehlgeschlagen: '.$install->message(),
                ['packages' => $packages],
            );
        }

        if (! PhpVersions::installed($version)) {
            throw AgentException::execFailed(
                sprintf('apt meldet Erfolg, %s fehlt trotzdem.', PhpVersions::binary($version)),
            );
        }

        $context->progress(80, 'Standard-Pool abschalten');
        $disabled = $this->disableDistributionPool($version);

        // Ohne eigenen Pool bleibt die Unit stehen; mit einem — etwa nach der
        // erneuten Installation einer Version, deren Abonnements noch da
        // sind — wird sie gestartet.
        if (PhpPoolRemove::pools($version) === []) {
            $context->runner->run('systemctl', ['disable', '--now', PhpVersions::unit($version)], 60);
        } else {
            $context->runner->run('systemctl', ['enable', '--now', PhpVersions::unit($version)], 60);
        }

        $context->progress(100, 'fertig');

        return [
            'php_version' => $version,
            'installed' => true,
            'already' => false,
            'distribution_pool' => $disabled,
            'packages' => $packages,
            'available' => PhpVersions::available(),
        ];
    }

    /** @return bool Wurde der Pool der Distribution in diesem Lauf abgeschaltet? */
    private function disableDistributionPool(string $version): bool
    {
        $pool = PhpVersions::distributionPool($version);

        if (! is_file($pool)) {
            return false;
        }

        return rename($pool, $pool.self::DISABLED_SUFFIX);
    }
}
