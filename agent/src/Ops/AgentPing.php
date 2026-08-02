<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Version;

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

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        return [
            'agent' => Version::AGENT,
            'protocol' => Version::PROTOCOL,
            'php' => PHP_VERSION,
            'pid' => getmypid(),
            'uid' => posix_getuid(),
        ];
    }
}
