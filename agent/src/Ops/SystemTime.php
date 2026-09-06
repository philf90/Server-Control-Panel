<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\TimeState;

/**
 * Der Zeitabgleich des Servers (A11, `docs/106 §5`).
 *
 * Lesend, ohne Argumente, eine Quelle. Dass Lesen kein root braucht, ist
 * gemessen (`docs/81 §2.3r` M13) — dass es trotzdem hierher gehört, folgt aus
 * der Architekturgrenze und nicht aus den Rechten.
 *
 * **Die Zone des Servers steht nicht in der Antwort**, obwohl `timedatectl`
 * sie mitliefert und der Plan sie hier vorsah. Sie ist seit P6 beantwortet —
 * `App\Support\Cron\ServerZone` liest den Symlink, dem auch cron folgt und
 * dem `timedatectl` folgt (M11). Zwei Leser derselben Quelle sind zwei
 * Fassungen derselben Regel, und die zweite ist die, die veraltet; hier wären
 * es zwei Seiten desselben Panels, die verschiedene Serverzonen nennen.
 *
 * **Warum `show` und nicht `status`.** `status` ist für Menschen gesetzt und
 * beantwortet dieselbe Frage in Fliesstext; `show` gibt Schlüssel-Wert-Zeilen,
 * und die liest ein Programm ohne zu raten.
 *
 * **Warum nicht `show-timesync`.** Es setzt genau den Dienst voraus, dessen
 * Fehlen man wissen will: ohne `systemd-timesyncd` `rc=1` und `Failed to parse
 * bus message` (`docs/81 §2.3r` M7).
 */
final class SystemTime implements Op
{
    public static function name(): string
    {
        return 'system.time';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $antwort = $context->runner->run('timedatectl', ['show'], 10);

        $zustand = TimeState::read($antwort);

        // **Der Wortlaut bleibt hier.** Er gehört ins Protokoll des Agenten und
        // nicht in die Seite: Ein Serverzustand, der als Meldung beim Leser
        // ankommt, schickt ihn dorthin, wo nichts zu ändern ist (`docs/59`).
        // Ohne diese Zeile stünde der Grund nirgends — `Journal::command()`
        // hält den Rückgabewert fest und nicht, was danebenstand.
        if ($zustand['readable'] === false) {
            $context->journal->write('timedatectl nicht lesbar', [
                'reason' => $zustand['reason'],
                'code' => $antwort->code,
                'message' => $antwort->message(),
            ]);
        }

        return $zustand;
    }
}
