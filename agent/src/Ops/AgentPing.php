<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\Kontext;
use CloudSrv\Agent\Op;
use CloudSrv\Agent\Version;

/**
 * Lebenszeichen. Beantwortet die einzige Frage, die vor allen anderen kommt:
 * Läuft der Agent, und spricht er dieselbe Protokollversion wie die Anwendung?
 *
 * Die Bereitschaftsprüfung nach einem Update hängt daran, und der Rauchtest in
 * der CI auch.
 */
final class AgentPing implements Op
{
    public static function name(): string
    {
        return 'agent.ping';
    }

    public static function veraendernd(): bool
    {
        return false;
    }

    public function fuehreAus(array $args, Kontext $kontext): array
    {
        return [
            'agent' => Version::AGENT,
            'protokoll' => Version::PROTOKOLL,
            'php' => PHP_VERSION,
            'pid' => getmypid(),
            'uid' => posix_getuid(),
        ];
    }
}
