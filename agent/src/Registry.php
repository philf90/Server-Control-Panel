<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

use CloudSrv\Agent\Ops\AgentPing;
use CloudSrv\Agent\Ops\ConfigValidate;
use CloudSrv\Agent\Ops\ServiceStatus;
use CloudSrv\Agent\Ops\SystemInfo;

/**
 * Das Verzeichnis der Operationen.
 *
 * Diese Liste ist die Angriffsfläche des Agenten — sie vollständig zu lesen
 * muss möglich bleiben. Eine Operation, die hier nicht steht, gibt es nicht;
 * es gibt keinen Weg, eine zur Laufzeit nachzureichen.
 */
final class Registry
{
    /** @var array<string,Op> */
    private array $ops = [];

    public function __construct(Config $config)
    {
        $this->registriere(new AgentPing);
        $this->registriere(new SystemInfo);
        $this->registriere(new ServiceStatus);
        $this->registriere(new ConfigValidate($config->pruefbareWurzeln));
    }

    public function registriere(Op $op): void
    {
        $this->ops[$op::name()] = $op;
    }

    public function hole(string $name): Op
    {
        if (! isset($this->ops[$name])) {
            throw new AgentException(
                AgentException::UNKNOWN_OP,
                sprintf('Unbekannte Operation %s.', $name),
                ['bekannt' => $this->namen()],
            );
        }

        return $this->ops[$name];
    }

    /** @return list<string> */
    public function namen(): array
    {
        $namen = array_keys($this->ops);
        sort($namen);

        return $namen;
    }
}
