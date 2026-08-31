<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Catalog;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Units;

/**
 * Der Zustand aller Units, die dieses Panel betreibt oder braucht.
 *
 * ## Ein Prozess und nicht neunzehn
 *
 * Gemessen am 30. August 2026 gegen systemd 255: `systemctl show a b c`
 * beantwortet alle drei in **einem** Aufruf, die Blöcke durch eine Leerzeile
 * getrennt und in der gefragten Reihenfolge. Der Katalog hat neunzehn Einträge;
 * einzeln gefragt wären das neunzehn Prozessstarts für eine Seite, die sich
 * nachlädt.
 *
 * ## Warum der Termin aus einer zweiten Quelle kommt
 *
 * `systemctl show` gibt den nächsten Termin eines Timers in zwei Feldern, von
 * denen eines ein Zeitstempel **in der Zone des Servers** ist — und der Agent
 * setzt `TZ` nicht. `systemctl list-timers --output=json` rechnet ihn dagegen
 * selbst aus und liefert rohe Mikrosekunden; `null` heisst „kein Termin".
 *
 * **Diese zweite Frage darf fehlschlagen, ohne die erste mitzunehmen.** Sie ist
 * nur gegen systemd 255 gemessen, und die Zielplattformen fahren 249 bis 257.
 * Bleibt sie aus, fehlt das Datum — und `has_next`, an dem das Abnahmekriterium
 * hängt, steht trotzdem da, weil es aus `show` kommt und ohne Zeitzone
 * auskommt.
 *
 * > **Eine Frage, die ohne Rechnung zu beantworten ist, wird nicht an eine
 * > Rechnung gehängt** — sonst nimmt deren Fehlschlag die Antwort mit.
 *
 * Und `list-timers` ist deshalb auch nicht die Hauptquelle: Ein von Hand
 * gestoppter Timer verschwindet daraus vollständig, während `show` ihn weiter
 * beantwortet. Genau diesen Schaden soll die Seite zeigen.
 */
final class SystemUnitsList implements Op
{
    public static function name(): string
    {
        return 'system.units.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $katalog = Catalog::all();
        /** @var list<string> $namen */
        $namen = array_column($katalog, 'unit');

        $context->progress(20, 'Zustand der Units lesen');

        $antwort = $context->runner->run('systemctl', array_merge(
            ['show'],
            $namen,
            ['--property='.implode(',', Units::FIELDS), '--no-pager'],
        ), 30);

        // **Erst lesen, dann paaren.** Ein Dienst, den ein Timer startet,
        // steht zwischen seinen Läufen auf `inactive` — vier der eigenen zwölf
        // sind `Type=oneshot`. Die Zuordnung kommt aus `Triggers` am Timer und
        // damit aus derselben einen Antwort; der Kopf von `markScheduled`
        // begründet, warum nicht aus `TriggeredBy` am Dienst.
        $zeilen = Units::markScheduled(Units::readMany($namen, $antwort->stdout));
        /** @var array<string,array<string,mixed>> $nachName */
        $nachName = array_combine($namen, $zeilen);

        $context->progress(60, 'Termine der Timer lesen');
        $termine = $this->schedule($context);

        $vorhanden = static fn (string $unit): bool => ($nachName[$unit]['present'] ?? false) === true;

        $rows = [];

        foreach (Catalog::OWN as $unit) {
            $rows[] = $this->row($nachName[$unit], 'panel', true, true, $termine);
        }

        foreach (Catalog::foreign() as $rolle => $kandidaten) {
            /** @var list<string> $namenDerRolle */
            $namenDerRolle = array_keys($kandidaten);
            $gewaehlt = Catalog::pick($namenDerRolle, $vorhanden);

            if ($gewaehlt === null) {
                continue;
            }

            $rows[] = $this->row($nachName[$gewaehlt], $rolle, false, $kandidaten[$gewaehlt], $termine);
        }

        $context->progress(100, sprintf('%d Units gelesen', count($rows)));

        return ['units' => $rows];
    }

    /**
     * Die nächsten Termine, je Timer — oder nichts.
     *
     * Der Rückgabewert ist bewusst arm: Kommt hier nichts, fehlt auf der Seite
     * ein Datum, und kein Zustand ist deshalb falsch.
     *
     * @return array<string,int> Unitname => Unix-Sekunden
     */
    private function schedule(Context $context): array
    {
        $antwort = $context->runner->run('systemctl', [
            'list-timers', '--all', '--output=json', '--no-pager',
        ], 15);

        if (! $antwort->successful()) {
            return [];
        }

        $daten = json_decode($antwort->stdout, true);

        if (! is_array($daten)) {
            return [];
        }

        $termine = [];

        foreach ($daten as $zeile) {
            if (! is_array($zeile) || ! isset($zeile['unit']) || ! is_string($zeile['unit'])) {
                continue;
            }

            // `next` ist `null`, wenn es keinen Termin gibt — gemessen. Das ist
            // dieselbe Auskunft wie `has_next === false` und wird hier nicht
            // doppelt geführt: Fehlt der Schlüssel, zeigt die Seite kein Datum.
            if (! isset($zeile['next']) || ! is_int($zeile['next'])) {
                continue;
            }

            $termine[$zeile['unit']] = intdiv($zeile['next'], 1_000_000);
        }

        return $termine;
    }

    /**
     * Eine Zeile für die Anzeige.
     *
     * @param  array<string,mixed>  $zustand
     * @param  array<string,int>  $termine
     * @return array<string,mixed>
     */
    private function row(array $zustand, string $role, bool $own, bool $controlled, array $termine): array
    {
        $unit = is_string($zustand['unit'] ?? null) ? $zustand['unit'] : '';

        return $zustand + [
            'role' => $role,
            'own' => $own,
            'controlled' => $controlled,

            // Nur für Timer, und nur wenn `list-timers` geantwortet hat. `null`
            // heisst hier „kein Datum bekannt" und nicht „kein Termin" — das
            // sagt `has_next`.
            'next_elapse' => $termine[$unit] ?? null,
        ];
    }
}
