<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\AgentPing;
use SrvPanel\Agent\Ops\ConfigValidate;
use SrvPanel\Agent\Ops\PanelProvision;
use SrvPanel\Agent\Ops\PanelTls;
use SrvPanel\Agent\Ops\PanelUpdate;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Ops\ServiceAction;
use SrvPanel\Agent\Ops\ServiceStatus;
use SrvPanel\Agent\Ops\SystemInfo;

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
        $this->register(new AgentPing);
        $this->register(new SystemInfo);
        $this->register(new ServiceStatus);
        $this->register(new ConfigValidate($config->configRoots));
        $this->register(new ServiceAction);
        $this->register(new PanelProvision);
        $this->register(new PanelTls);
        $this->register(new PanelVhost);
        $this->register(new PanelUpdate);
    }

    public function register(Op $op): void
    {
        $this->ops[$op::name()] = $op;
    }

    public function get(string $name): Op
    {
        if (! isset($this->ops[$name])) {
            throw new AgentException(
                AgentException::UNKNOWN_OP,
                sprintf('Unbekannte Operation %s.', $name),
                ['known' => $this->names()],
            );
        }

        return $this->ops[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->ops);
        sort($names);

        return $names;
    }
}
