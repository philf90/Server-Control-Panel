<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Notify\Target;
use SrvPanel\Agent\Op;

/**
 * Was über das Meldeziel gesagt werden darf.
 *
 * **Sie liest und schaltet nicht** (`mutating() === false`). Gefragt wird der
 * Agent und nicht die Datenbank: Das Ziel liegt 0600 root in einem
 * 0700-Verzeichnis, und eine zweite Liste im Panel wäre die zweite Wahrheit zu
 * derselben Frage.
 *
 * **`null` heisst „keines hinterlegt" und nicht „nicht feststellbar".** Den
 * zweiten Zustand gibt es hier: Antwortet der Agent gar nicht, kommt keine
 * Antwort, und der Aufrufer unterscheidet das an der Ausnahme. Beide auf
 * dieselbe leere Antwort abzubilden hiesse, dem Betreiber „kein Ziel" zu
 * zeigen, während in Wahrheit eines meldet.
 */
final class NotifyTargetDescribe implements Op
{
    public function __construct(private readonly Target $target = new Target) {}

    public static function name(): string
    {
        return 'notify.target.describe';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        return ['target' => $this->target->describe()];
    }
}
