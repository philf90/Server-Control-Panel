<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Guard;
use CloudSrv\Agent\Kontext;
use CloudSrv\Agent\Op;

/**
 * Prüft eine Konfigurationsdatei mit dem Prüfer des jeweiligen Dienstes.
 *
 * Das ist der Baustein für Leitsatz 3 aus dem Plan: erst prüfen, dann
 * übernehmen. Er steht absichtlich schon in P0, obwohl noch nichts geschrieben
 * wird — die Reihenfolge „Prüfer zuerst, Schreiben danach" ist leichter
 * einzuhalten, wenn der Prüfer schon da ist.
 *
 * Die Art bestimmt das Programm; der Aufrufer wählt aus einer festen Liste und
 * gibt nie ein Programm an.
 */
final class ConfigValidate implements Op
{
    /** @var array<string,array{programm:string,argumente:list<string>}> */
    private const PRUEFER = [
        'nginx' => ['programm' => 'nginx', 'argumente' => ['-t', '-c']],
        'sshd' => ['programm' => 'sshd', 'argumente' => ['-t', '-f']],
        'php-fpm' => ['programm' => 'php-fpm', 'argumente' => ['-t', '-y']],
        'zone' => ['programm' => 'named-checkzone', 'argumente' => []],
    ];

    /** @param list<string> $erlaubteWurzeln */
    public function __construct(private readonly array $erlaubteWurzeln) {}

    public static function name(): string
    {
        return 'config.validate';
    }

    public static function veraendernd(): bool
    {
        return false;
    }

    public function fuehreAus(array $args, Kontext $kontext): array
    {
        $art = Guard::enum($args['art'] ?? null, array_keys(self::PRUEFER), 'art');
        $pfad = Guard::pathInside($args['pfad'] ?? null, $this->erlaubteWurzeln);
        $pruefer = self::PRUEFER[$art];

        $argumente = $pruefer['argumente'];

        if ($art === 'zone') {
            $zone = Guard::string($args['zone'] ?? null, 'zone');
            if (! preg_match('/^[a-zA-Z0-9.\-]{1,253}$/', $zone)) {
                throw AgentException::badRequest('Unzulässiger Zonenname.', ['zone' => $zone]);
            }
            $argumente[] = $zone;
        }

        $argumente[] = $pfad;

        $ergebnis = $kontext->runner->run($pruefer['programm'], $argumente, 30);

        return [
            'art' => $art,
            'pfad' => $pfad,
            'gueltig' => $ergebnis->erfolgreich(),
            'meldung' => $ergebnis->meldung(),
            'code' => $ergebnis->code,
        ];
    }
}
