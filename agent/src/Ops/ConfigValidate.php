<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

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
    /** @var array<string,array{program:string,arguments:list<string>}> */
    private const VALIDATORS = [
        'nginx' => ['program' => 'nginx', 'arguments' => ['-t', '-c']],
        'sshd' => ['program' => 'sshd', 'arguments' => ['-t', '-f']],
        'php-fpm' => ['program' => 'php-fpm', 'arguments' => ['-t', '-y']],
        'zone' => ['program' => 'named-checkzone', 'arguments' => []],
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

        // Nicht $args: Das ist der Parameter mit der Anfrage. Beim Umbenennen
        // auf englische Bezeichner hatte die lokale Liste denselben Namen
        // bekommen und ihn überschrieben — der Zonenname wurde danach aus der
        // Argumentliste gelesen statt aus der Anfrage.
        $arguments = $validator['arguments'];

        if ($kind === 'zone') {
            $zone = Guard::string($args['zone'] ?? null, 'zone');
            if (! preg_match('/^[a-zA-Z0-9.\-]{1,253}$/', $zone)) {
                throw AgentException::badRequest('Unzulässiger Zonenname.', ['zone' => $zone]);
            }
            $arguments[] = $zone;
        }

        $arguments[] = $path;

        $result = $context->runner->run($validator['program'], $arguments, 30);

        return [
            'kind' => $kind,
            'path' => $path,
            'valid' => $result->successful(),
            'message' => $result->message(),
            'code' => $result->code,
        ];
    }
}
