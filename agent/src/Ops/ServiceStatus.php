<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\Guard;
use CloudSrv\Agent\Kontext;
use CloudSrv\Agent\Op;

/**
 * Zustand einer systemd-Unit.
 *
 * Abgefragt wird mit `systemctl show --property=…`, nicht mit `systemctl
 * status`: Die Spaltenansicht ist für Menschen gemacht, und ein Spaltenparser
 * bricht an der ersten Unit-Beschreibung, die aussieht wie ein Statuswort.
 * `show` liefert Schlüssel=Wert je Zeile und ist damit eindeutig.
 */
final class ServiceStatus implements Op
{
    private const FELDER = [
        'Id',
        'Description',
        'LoadState',
        'ActiveState',
        'SubState',
        'UnitFileState',
        'MainPID',
        'ExecMainStartTimestamp',
        'NRestarts',
    ];

    public static function name(): string
    {
        return 'service.status';
    }

    public static function veraendernd(): bool
    {
        return false;
    }

    public function fuehreAus(array $args, Kontext $kontext): array
    {
        $unit = Guard::unitName($args['unit'] ?? null);

        $ergebnis = $kontext->runner->run('systemctl', [
            'show',
            $unit,
            '--property='.implode(',', self::FELDER),
            '--no-pager',
        ], 15);

        // `systemctl show` beantwortet auch eine unbekannte Unit mit Code 0 und
        // LoadState=not-found. Der Rückgabecode taugt hier also nicht als
        // Prüfung — der LoadState schon.
        $werte = [];

        foreach ($ergebnis->zeilen() as $zeile) {
            if (! str_contains($zeile, '=')) {
                continue;
            }
            [$schluessel, $wert] = explode('=', $zeile, 2);
            $werte[$schluessel] = $wert;
        }

        return [
            'unit' => $unit,
            'vorhanden' => ($werte['LoadState'] ?? 'not-found') !== 'not-found',
            'beschreibung' => $werte['Description'] ?? '',
            'aktiv' => $werte['ActiveState'] ?? 'unknown',
            'unterzustand' => $werte['SubState'] ?? 'unknown',
            'autostart' => $werte['UnitFileState'] ?? 'unknown',
            'pid' => (int) ($werte['MainPID'] ?? 0),
            'neustarts' => (int) ($werte['NRestarts'] ?? 0),
            'seit' => $werte['ExecMainStartTimestamp'] ?? '',
        ];
    }
}
