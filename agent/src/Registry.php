<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Ops\AcmeAccount;
use SrvPanel\Agent\Ops\AcmeCertificate;
use SrvPanel\Agent\Ops\AcmeCertificateInfo;
use SrvPanel\Agent\Ops\AcmeCertificateRemove;
use SrvPanel\Agent\Ops\AgentPing;
use SrvPanel\Agent\Ops\CertificateUpload;
use SrvPanel\Agent\Ops\ConfigValidate;
use SrvPanel\Agent\Ops\DnsCredentialForget;
use SrvPanel\Agent\Ops\DnsCredentialList;
use SrvPanel\Agent\Ops\DnsCredentialStore;
use SrvPanel\Agent\Ops\PanelProvision;
use SrvPanel\Agent\Ops\PanelTls;
use SrvPanel\Agent\Ops\PanelTlsInfo;
use SrvPanel\Agent\Ops\PanelUpdate;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Ops\PhpPoolApply;
use SrvPanel\Agent\Ops\PhpPoolRemove;
use SrvPanel\Agent\Ops\PhpVersionInstall;
use SrvPanel\Agent\Ops\PhpVersionList;
use SrvPanel\Agent\Ops\PhpVersionRemove;
use SrvPanel\Agent\Ops\ServiceAction;
use SrvPanel\Agent\Ops\ServiceStatus;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\SubscriptionQuota;
use SrvPanel\Agent\Ops\SubscriptionRemove;
use SrvPanel\Agent\Ops\SubscriptionResume;
use SrvPanel\Agent\Ops\SubscriptionSuspend;
use SrvPanel\Agent\Ops\SubscriptionUsage;
use SrvPanel\Agent\Ops\SystemInfo;
use SrvPanel\Agent\Ops\WebIsolationProbe;
use SrvPanel\Agent\Ops\WebLogrotate;
use SrvPanel\Agent\Ops\WebLogsTail;
use SrvPanel\Agent\Ops\WebserverDetect;
use SrvPanel\Agent\Ops\WebSiteApply;
use SrvPanel\Agent\Ops\WebSiteRemove;

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
        $this->register(new PanelTlsInfo);
        $this->register(new PanelVhost);
        $this->register(new PanelUpdate);
        $this->register(new SubscriptionProvision);
        $this->register(new SubscriptionRemove);
        $this->register(new SubscriptionSuspend);
        $this->register(new SubscriptionResume);
        $this->register(new SubscriptionUsage);
        $this->register(new SubscriptionQuota);

        // P3 — Web und PHP.
        $this->register(new WebserverDetect);
        $this->register(new WebSiteApply);
        $this->register(new WebSiteRemove);
        $this->register(new WebLogsTail);
        $this->register(new WebLogrotate);
        $this->register(new WebIsolationProbe);
        $this->register(new PhpVersionList);
        $this->register(new PhpVersionInstall);
        $this->register(new PhpVersionRemove);
        $this->register(new PhpPoolApply);
        $this->register(new PhpPoolRemove);

        // P4 — TLS.
        $this->register(new AcmeAccount);
        $this->register(new AcmeCertificate);
        $this->register(new AcmeCertificateInfo);
        $this->register(new AcmeCertificateRemove);
        $this->register(new CertificateUpload);
        $this->register(new DnsCredentialStore);
        $this->register(new DnsCredentialList);
        $this->register(new DnsCredentialForget);
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
