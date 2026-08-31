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

    /**
     * Was gesteuert werden darf.
     *
     * **Nur die eigenen Units, und das ist am 30. August 2026 entschieden
     * worden.** Bis dahin standen hier vier Einträge; drei davon waren
     * ungenutzt und einer war wirkungslos.
     *
     * `php*-fpm.service` hat **nie** etwas erlaubt: Der Vergleicher unten löst
     * einen Stern nur am Ende eines Musters auf, ein Stern in der Mitte fällt
     * in den Gleichheitsvergleich — und eine Unit, die wörtlich so heisst,
     * lässt {@see Guard::unitName()} gar nicht erst durch.
     *
     * > **Ein Muster in einer Positivliste, das die Liste selbst nicht auflösen
     * > kann, ist kein Eintrag — es ist eine Behauptung.**
     *
     * Gefährlich war er in genau einer Richtung: Hätte jemand später den
     * Vergleicher erweitert, wäre PHP-FPM stillschweigend steuerbar geworden,
     * ohne dass das jemand entschieden hat.
     *
     * `nginx.service` und `mariadb.service` haben etwas erlaubt, das niemand
     * benutzt. Ausgezählt: Der einzige Aufrufer dieser Operation ist
     * `Setup`, und der schickt `srvpanel-web`, `-worker` und `-metrics`.
     * Beide Dienste werden über eigene, eng gefasste Operationen bedient —
     * nginx über {@see PanelTls} und `NginxApply` (ohne `{@see}`, weil Pint
     * daraus sonst einen `use`-Eintrag macht, den nur dieser Satz braucht),
     * die Datenbank über {@see DbRemoteAccess}. Dort steht auch der Grund,
     * warum: Ein `reload` nach einer geschriebenen Datei ist etwas anderes als
     * ein `stop` aus der Oberfläche.
     *
     * > **Eine Positivliste, die mehr erlaubt, als irgendwer benutzt,
     * > beschreibt eine Absicht und nicht den Gebrauch.**
     *
     * Was ein späterer Schritt braucht — Knöpfe je Unit auf der Dienste-Seite —
     * kommt hier mit Begründung dazu und nicht als Erbe aus P0.
     *
     * @var list<string> Genaue Namen oder Präfixe mit Stern am Ende.
     */
    private const ALLOWED_UNITS = [
        'srvpanel-*',
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
