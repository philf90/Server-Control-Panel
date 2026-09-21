<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Notify\Target;
use SrvPanel\Agent\Op;

/**
 * Das Meldeziel wieder entfernen.
 *
 * **Es gibt einen Weg hinein, also gehört einer hinaus dazu** — derselbe Satz
 * wie bei {@see DnsCredentialForget}. Ein Ziel, das sich nur überschreiben und
 * nie löschen lässt, meldet weiter an eine Adresse, die niemand mehr will, und
 * es gibt keine Stelle, an der das auffiele.
 */
final class NotifyTargetForget implements Op
{
    public function __construct(private readonly Target $target = new Target) {}

    public static function name(): string
    {
        return 'notify.target.forget';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        return ['removed' => $this->target->forget()];
    }
}
