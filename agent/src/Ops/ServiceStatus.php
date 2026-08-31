<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Units;

/**
 * Zustand einer systemd-Unit — eines Dienstes oder eines Timers.
 *
 * Abgefragt wird mit `systemctl show --property=…`, nicht mit `systemctl
 * status`: Die Spaltenansicht ist für Menschen gemacht, und ein Spaltenparser
 * bricht an der ersten Unit-Beschreibung, die aussieht wie ein Statuswort.
 * `show` liefert Schlüssel=Wert je Zeile und ist damit eindeutig.
 *
 * **Und nicht mit `systemctl list-timers`, obwohl das für Timer bequemer
 * wäre.** Gemessen am 30. August 2026 (`docs/89 §3`): Ein von Hand gestoppter
 * Timer verschwindet aus `list-timers --all` vollständig, während `show` ihn
 * weiter beantwortet. Genau diesen Schaden soll A2 sichtbar machen.
 *
 * > **Eine Liste, die nur zeigt, was läuft, kann das Fehlende nicht melden.**
 *
 * Das Lesen selbst steht in {@see Units} — es ist die eigentliche Arbeit, und
 * dort lässt es sich ohne laufendes systemd prüfen.
 */
final class ServiceStatus implements Op
{
    public static function name(): string
    {
        return 'service.status';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $unit = Guard::unitName($args['unit'] ?? null);

        $result = $context->runner->run('systemctl', [
            'show',
            $unit,
            '--property='.implode(',', Units::FIELDS),
            '--no-pager',
        ], 15);

        // `systemctl show` beantwortet auch eine unbekannte Unit mit Code 0 und
        // LoadState=not-found. Der Rückgabecode taugt hier also nicht als
        // Prüfung — der LoadState schon, und den liest der Leser.
        return Units::read($unit, $result->lines());
    }
}
