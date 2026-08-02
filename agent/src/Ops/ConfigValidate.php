<?php

declare(strict_types=1);

namespace CloudSrv\Agent\Ops;

use CloudSrv\Agent\AgentException;
use CloudSrv\Agent\Context;
use CloudSrv\Agent\Guard;
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
    private const VALIDATORS = [
        'nginx' => ['program' => 'nginx', 'argumente' => ['-t', '-c']],
        'sshd' => ['program' => 'sshd', 'argumente' => ['-t', '-f']],
        'php-fpm' => ['program' => 'php-fpm', 'argumente' => ['-t', '-y']],
        'zone' => ['program' => 'named-checkzone', 'argumente' => []],
    ];

    /** @param list<string> $allowedRoots */
    public function __construct(private readonly array $allowedRoots) {}

    public static function name(): string
    {
        return 'config.validate';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $kind = Guard::enum($args['kind'] ?? null, array_keys(self::VALIDATORS), 'kind');
        $path = Guard::pathInside($args['path'] ?? null, $this->allowedRoots);
        $validator = self::VALIDATORS[$kind];

        $args = $validator['argumente'];

        if ($kind === 'zone') {
            $zone = Guard::string($args['zone'] ?? null, 'zone');
            if (! preg_match('/^[a-zA-Z0-9.\-]{1,253}$/', $zone)) {
                throw AgentException::badRequest('Unzulässiger Zonenname.', ['zone' => $zone]);
            }
            $args[] = $zone;
        }

        $args[] = $path;

        $result = $context->runner->run($validator['program'], $args, 30);

        return [
            'kind' => $kind,
            'path' => $path,
            'valid' => $result->successful(),
            'message' => $result->message(),
            'code' => $result->code,
        ];
    }
}
