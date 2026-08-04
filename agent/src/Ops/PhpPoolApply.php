<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\PoolTemplate;

/**
 * Den FPM-Pool eines Abonnements in einer Version anlegen oder auffrischen.
 *
 * **Erst prüfen, dann übernehmen** — derselbe Ablauf wie bei nginx: schreiben,
 * `php-fpm<version> -t`, und bei einer Ablehnung den vorigen Stand zurück. Ein
 * Pool mit einem Fehler nimmt beim Neuladen **alle** Pools dieser Version mit;
 * ein falsch geschriebener Pool eines Abonnements legte damit die Websites
 * aller anderen still.
 *
 * **Die Zahl der Prozesse ist ein Kontingent und keine Schätzung.**
 * `pm.max_children` kommt aus `Quota::FpmProcesses` des Plans. Der
 * Kontingentkatalog nennt das schon als Wirkung („Obergrenze des
 * PHP-FPM-Pools. Bestimmt, wie viele Anfragen gleichzeitig laufen"), und hier
 * wird sie eingelöst.
 */
final class PhpPoolApply implements Op
{
    /** Mehr Prozesse hat kein Abonnement — dieselbe Grenze wie im Katalog. */
    public const MAX_CHILDREN = 512;

    public static function name(): string
    {
        return 'php.pool.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $version = PhpVersions::normalize($args['php_version'] ?? null);
        $maxChildren = self::maxChildren($args['max_children'] ?? null);

        if (! PhpVersions::installed($version)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('PHP %s ist auf diesem Server nicht installiert.', $version),
                ['php_version' => $version, 'available' => PhpVersions::available()],
            );
        }

        if (! is_dir(SubscriptionProvision::VHOSTS.'/'.$subscription)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Das Abonnement hat kein Verzeichnis — erst subscription.provision.',
                ['subscription' => $subscription],
            );
        }

        $file = PhpVersions::poolFile($version, $user);
        $before = is_file($file) ? (string) file_get_contents($file) : null;

        $context->progress(30, 'Pool schreiben');

        $directory = dirname($file);

        if (! is_dir($directory)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('%s fehlt — ist php%s-fpm installiert?', $directory, $version),
            );
        }

        file_put_contents($file, PoolTemplate::render($subscription, $user, $version, $maxChildren));
        chmod($file, 0o644);

        $context->progress(60, 'Konfiguration prüfen');
        $check = $context->runner->run(PhpVersions::program($version), ['-t'], 30);

        if (! $check->successful()) {
            if ($before === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $before);
            }

            throw AgentException::execFailed('PHP-FPM hat den Pool abgelehnt: '.$check->message());
        }

        $context->progress(80, 'FPM neu laden');
        $this->reload($context, $version);

        $context->progress(100, 'fertig');

        return [
            'pool' => $file,
            'socket' => PhpVersions::socket($version, $user),
            'php_version' => $version,
            'max_children' => $maxChildren,
            'replaced' => $before !== null,
        ];
    }

    /**
     * Den Masterprozess dieser Version laden.
     *
     * `enable` dazu: Ohne ihn wäre der Pool nach dem nächsten Neustart des
     * Servers weg — die Datei läge da, und niemand läse sie. `reload-or-restart`
     * statt `reload`, weil die Unit nach `php.version.install` bewusst steht:
     * Ein FPM ohne einen einzigen Pool startet nicht.
     */
    private function reload(Context $context, string $version): void
    {
        $unit = PhpVersions::unit($version);

        $context->runner->run('systemctl', ['enable', $unit], 30);

        $result = $context->runner->run('systemctl', ['reload-or-restart', $unit], 60);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                sprintf('%s liess sich nicht neu laden: %s', $unit, $result->message()),
            );
        }
    }

    private static function maxChildren(mixed $value): int
    {
        $number = Guard::int($value, 'max_children');

        if ($number < 1 || $number > self::MAX_CHILDREN) {
            throw AgentException::badRequest(
                sprintf('max_children liegt zwischen 1 und %d.', self::MAX_CHILDREN),
                ['max_children' => $number],
            );
        }

        return $number;
    }
}
