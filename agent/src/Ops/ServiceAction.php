<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Eine systemd-Unit steuern.
 *
 * **Warum eine zweite, engere Liste als bei service.status.** Den Zustand
 * einer beliebigen Unit zu lesen ist harmlos. Eine beliebige Unit zu starten
 * oder zu stoppen ist es nicht: Damit ließe sich sshd abschalten, ein
 * Backup-Dienst anhalten oder ein fremder Container gestartet werden. In P0
 * darf diese Operation deshalb nur an das, was das Panel selbst betreibt.
 *
 * Die Liste wächst mit den Modulen — aber sie wächst im Code des Agenten und
 * nicht dadurch, dass die Anwendung einen anderen Namen schickt.
 */
final class ServiceAction implements Op
{
    private const ACTIONS = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'];

    /** @var list<string> Genaue Namen oder Präfixe mit Stern am Ende. */
    private const ALLOWED_UNITS = [
        'srvpanel-*',
        'nginx.service',
        'mariadb.service',
        'php*-fpm.service',
    ];

    public static function name(): string
    {
        return 'service.action';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $unit = Guard::unitName($args['unit'] ?? null);
        $action = Guard::enum($args['action'] ?? null, self::ACTIONS, 'action');

        if (! self::allows($unit)) {
            throw AgentException::denied(sprintf('Die Unit %s darf das Panel nicht steuern.', $unit));
        }

        $context->progress(10, sprintf('%s %s', $action, $unit));
        $result = $context->stream('systemctl', [$action, $unit], 90);

        return [
            'unit' => $unit,
            'action' => $action,
            'ok' => $result->successful(),
            'message' => $result->successful() ? '' : $result->message(),
        ];
    }

    /**
     * Darf diese Unit gesteuert werden?
     *
     * Öffentlich und statisch, damit `UnitCatalogTest` die **tatsächliche**
     * Entscheidung fragen kann statt sie nachzubauen. Ein Wächter, der die
     * Regel zum zweiten Mal aufschreibt, prüft seine eigene Abschrift.
     */
    public static function allows(string $unit): bool
    {
        foreach (self::ALLOWED_UNITS as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($unit, rtrim($pattern, '*'))) {
                    return true;
                }

                continue;
            }

            if ($unit === $pattern) {
                return true;
            }
        }

        return false;
    }
}
