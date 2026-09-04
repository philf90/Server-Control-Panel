<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Site;

/**
 * Den Wartungsmodus ein- und ausschalten (A12, `docs/101 §4`).
 *
 * ## Eine Datei und kein Rundlauf
 *
 * Geschaltet wird das Anlegen und Löschen von {@see Maintenance::FLAG}; die
 * Vhost-Dateien bleiben unberührt. Damit gibt es keine Warteschlange, keine
 * halb umgestellten Domains und keinen Zustand, der zwischen zwei Domains
 * auseinanderlaufen kann.
 *
 * **Kein Reload, und das ist gemessen** (`docs/81 §2.3p`): In der Messrunde
 * wurde die Datei bei laufendem nginx angelegt und wieder entfernt, ohne einen
 * einzigen Reload — und die Antworten wechselten zwischen 200 und 503. `if
 * (-f …)` wird je Anfrage ausgewertet.
 *
 * > **Ein Reload, den man vorsichtshalber mitschickt, ist ein Neustart, den
 * > niemand gebraucht hat — und er kann scheitern.**
 *
 * ## Warum die Endzeit nicht hier steht
 *
 * Sie ist Text der Seite und reist mit der Vorlage über `web.site.apply`
 * ({@see Site::$maintenanceUntil}). **Schalten ist eine Datei,
 * Beschriften ist die Vorlage** — die Trennung ist es, die A12 billig macht.
 * Läge die Endzeit hier, müsste jedes Umschalten alle Blöcke neu schreiben,
 * und genau das soll es nicht.
 *
 * ## Was zurückkommt, ist gemessen und nicht wiederholt
 *
 * Nach dem Schalten wird die Datei **nachgesehen**. Ein Ergebnis, das nur den
 * Wunsch zurückgibt, sagt über den Ausgang nichts — die Familie aus
 * `docs/86 §5`, hier vermeidbar, weil der Zustand unmittelbar ablesbar ist.
 */
final class WebMaintenanceSet implements Op
{
    /**
     * Was in der Flagdatei steht.
     *
     * Ihre blosse Anwesenheit schaltet; der Inhalt ist für den Menschen, der
     * sie auf dem Server findet und wissen will, was sie tut und wer sie
     * wieder loswird.
     */
    public const NOTE = "Von srvpanel-agentd erzeugt.\n".
        "Solange diese Datei liegt, beantworten alle Kundendomains jede Anfrage\n".
        "mit 503 — ausgenommen die Prüfadresse von ACME.\n".
        "Ausschalten über das Panel (Server → Wartungsmodus) oder durch Löschen\n".
        "dieser Datei; ein Reload von nginx ist dafür nicht nötig.\n";

    /**
     * Der Ablageort ist einsetzbar, damit er messbar ist.
     *
     * **Die Vorgabe ist die Wahrheit** — {@see Maintenance::FLAG} ist derselbe
     * Pfad, den die Wache im Server-Block nennt, und `MaintenanceSwitchTest`
     * hält, dass die beiden nicht auseinanderlaufen. Ein Wächter, der gegen
     * einen eigenen Pfad misst, prüfte seine eigene Erfindung.
     */
    public function __construct(private readonly string $flag = Maintenance::FLAG) {}

    public static function name(): string
    {
        return 'web.maintenance.set';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $enabled = $args['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw AgentException::badRequest(
                'Der Wartungsmodus wird mit einem Wahrheitswert geschaltet.',
                ['enabled' => is_scalar($enabled) ? (string) $enabled : gettype($enabled)],
            );
        }

        $enabled ? $this->turnOn() : $this->turnOff();

        // **Nachgesehen und nicht behauptet.** Zwischen dem Schreiben und
        // dieser Zeile kann etwas schiefgegangen sein, das keine Ausnahme
        // geworfen hat — ein voller Datenträger etwa.
        clearstatcache(true, $this->flag);
        $ist = is_file($this->flag);

        if ($ist !== $enabled) {
            throw AgentException::execFailed(sprintf(
                'Der Wartungsmodus liess sich nicht %s: %s liegt %s.',
                $enabled ? 'einschalten' : 'ausschalten',
                $this->flag,
                $ist ? 'weiterhin' : 'nicht',
            ));
        }

        return ['enabled' => $ist, 'flag' => $this->flag];
    }

    private function turnOn(): void
    {
        NginxApply::write($this->flag, self::NOTE);
    }

    /**
     * Ausschalten ist wiederholbar.
     *
     * Eine Datei, die nicht da ist, ist der gewünschte Zustand und kein
     * Fehlschlag — `unlink()` gibt in diesem Fall `false` zurück, und wer das
     * für einen Fehler nimmt, macht aus dem zweiten Ausschalten einen Abbruch.
     */
    private function turnOff(): void
    {
        if (! is_file($this->flag)) {
            return;
        }

        if (! unlink($this->flag)) {
            throw AgentException::execFailed(sprintf('%s liess sich nicht entfernen.', $this->flag));
        }
    }
}
