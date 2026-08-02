<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\Context;
use CloudSrv\Agent\Guard;
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
    private const FIELDS = [
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
            '--property='.implode(',', self::FIELDS),
            '--no-pager',
        ], 15);

        // `systemctl show` beantwortet auch eine unbekannte Unit mit Code 0 und
        // LoadState=not-found. Der Rückgabecode taugt hier also nicht als
        // Prüfung — der LoadState schon.
        $values = [];

        foreach ($result->lines() as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return [
            'unit' => $unit,
            'present' => ($values['LoadState'] ?? 'not-found') !== 'not-found',
            'description' => $values['Description'] ?? '',
            'active_state' => $values['ActiveState'] ?? 'unknown',
            'sub_state' => $values['SubState'] ?? 'unknown',
            'unit_file_state' => $values['UnitFileState'] ?? 'unknown',
            'pid' => (int) ($values['MainPID'] ?? 0),
            'restarts' => (int) ($values['NRestarts'] ?? 0),
            'since' => $values['ExecMainStartTimestamp'] ?? '',
        ];
    }
}
