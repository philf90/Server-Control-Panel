<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Apt;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;

/**
 * Die Paketlisten auffrischen — und sagen, welche Quelle dabei ausgefallen ist.
 *
 * ## Warum das eine eigene Operation ist und kein Nebeneffekt von `list`
 *
 * Weil es **ändert**. `system.packages.list` liest ausschliesslich mit `-s`
 * und nimmt die Sperre nicht; ein `apt-get update` bei jedem Seitenaufruf
 * nähme sie, dauerte auf einem kalten Server über eine Minute und kollidierte
 * mit jedem Lauf, den der Betreiber gerade angestossen hat.
 *
 * > **Eine Anzeige, die beim Ansehen etwas verändert, ist keine Anzeige.**
 *
 * ## Und warum sie nicht abbricht, wenn eine Quelle fehlt
 *
 * Das ist die zweite Hälfte von M5 (`docs/81 §2.1b`): {@see Apt} liest und
 * entscheidet nichts, die Aufrufer entscheiden verschieden.
 * `php.version.install` bricht an **seiner** toten Quelle ab, weil es ohne sie
 * das falsche Paket installierte. Hier ist es umgekehrt — eine unerreichbare
 * Drittquelle darf nicht verhindern, dass die Sicherheitsupdates des
 * Distributionsarchivs sichtbar werden.
 *
 * Gemeldet wird sie trotzdem, und zwar mit Namen. Das ist der Unterschied
 * zwischen „nicht nachgesehen" und „nichts zu tun".
 *
 * **Abgebrochen wird nur, wenn der Lauf selbst scheitert** — kaputte
 * Quelldatei, fehlender Schlüssel, klemmende Sperre. Der Rückgabewert trägt
 * diesen Fall sehr wohl; er trägt nur den anderen nicht.
 */
final class SystemPackagesRefresh implements Op
{
    public static function name(): string
    {
        return 'system.packages.refresh';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        AptLock::ensureFree($context);

        $context->progress(10, 'Paketlisten werden aufgefrischt');

        $apt = Apt::refresh($context);

        if (! $apt->result->successful()) {
            throw AgentException::execFailed(
                'Die Paketlisten liessen sich nicht auffrischen: '.$apt->result->message(),
            );
        }

        /*
         * **`summary()` ist leer, wenn alles erreicht wurde** — eine leere
         * Fortschrittsmeldung sähe aus, als sei etwas ausgefallen. Der Satz
         * steht deshalb hier und die Namen daneben.
         */
        $context->progress(100, $apt->reachedEverything()
            ? 'alle Quellen erreicht'
            : 'nicht erreicht: '.$apt->summary());

        return [
            'unreachable' => $apt->unreachable,
            'reached_everything' => $apt->reachedEverything(),
            'summary' => $apt->summary(),
        ];
    }
}
