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
use SrvPanel\Agent\Ops\DbDatabaseCreate;
use SrvPanel\Agent\Ops\DbDatabaseRemove;
use SrvPanel\Agent\Ops\DbDumpCreate;
use SrvPanel\Agent\Ops\DbDumpImport;
use SrvPanel\Agent\Ops\DbDumpRemove;
use SrvPanel\Agent\Ops\DbIsolationProbe;
use SrvPanel\Agent\Ops\DbRemoteAccess;
use SrvPanel\Agent\Ops\DbRestore;
use SrvPanel\Agent\Ops\DbServerInfo;
use SrvPanel\Agent\Ops\DbUsage;
use SrvPanel\Agent\Ops\DbUserCreate;
use SrvPanel\Agent\Ops\DbUserGrant;
use SrvPanel\Agent\Ops\DbUserLock;
use SrvPanel\Agent\Ops\DbUserPassword;
use SrvPanel\Agent\Ops\DbUserRemove;
use SrvPanel\Agent\Ops\DnsCredentialForget;
use SrvPanel\Agent\Ops\DnsCredentialList;
use SrvPanel\Agent\Ops\DnsCredentialStore;
use SrvPanel\Agent\Ops\PanelProvision;
use SrvPanel\Agent\Ops\PanelTls;
use SrvPanel\Agent\Ops\PanelTlsInfo;
use SrvPanel\Agent\Ops\PanelUpdate;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Ops\PgDatabaseCreate;
use SrvPanel\Agent\Ops\PgDatabaseRemove;
use SrvPanel\Agent\Ops\PgRoleCreate;
use SrvPanel\Agent\Ops\PgRoleGrant;
use SrvPanel\Agent\Ops\PgRoleLock;
use SrvPanel\Agent\Ops\PgRoleRemove;
use SrvPanel\Agent\Ops\PgServerInfo;
use SrvPanel\Agent\Ops\PgServerInstall;
use SrvPanel\Agent\Ops\PgUsage;
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

        /*
         * P5 — Datenbanken (docs/36).
         *
         * Die `remove`-Hälften stehen zuerst, und nicht aus Ordnungsliebe:
         * docs/35 hat freigelegt, dass dieses System ein Zertifikat nie löschen
         * konnte — ein Jahr lang, weil `create` zuerst gebaut wurde und danach
         * funktionierte. `RemovalPathTest` hält diese Reihenfolge ab jetzt für
         * die ganze Registratur fest.
         */
        $this->register(new DbServerInfo);
        $this->register(new DbRemoteAccess);
        $this->register(new DbDumpImport);
        $this->register(new DbDatabaseRemove);
        $this->register(new DbDatabaseCreate);
        $this->register(new DbUserRemove);
        $this->register(new DbUserCreate);
        $this->register(new DbUserPassword);
        $this->register(new DbUserGrant);
        $this->register(new DbUserLock);

        // Sichern und Zurückspielen — auch hier `remove` zuerst. Eine Sicherung
        // ist das, was P5 auf dem System hinterlässt und was beliebig gross
        // wird.
        $this->register(new DbDumpRemove);
        $this->register(new DbDumpCreate);
        $this->register(new DbRestore);

        // Die Messung. Sie steht ausserhalb der Paare oben, weil sie nichts
        // anlegt — `RemovalPathTest` fragt sie deshalb nicht nach einem
        // Gegenstück, und `db.usage` steht mit derselben Begründung in
        // `AgentOperationReachTest::WITHOUT_LIFECYCLE` wie `subscription.usage`:
        // Sie läuft am Zeitgeber und nicht an einem Lebenslauf.
        $this->register(new DbUsage);

        // Die Selbstprobe des Abnahmelaufs. Sie legt eine Tabelle an und räumt
        // nichts weg — das tut `srvpanel acceptance-db`, indem es die
        // Datenbanken danach entfernt. Ohne Lebenslauf: Im Bestand des Panels
        // steht zu ihr nichts.
        $this->register(new DbIsolationProbe);

        /*
         * P5b — PostgreSQL (docs/38 §7).
         *
         * **Nur diese beiden, und das ist kein Zwischenstand, sondern die
         * Regel.** Die übrigen `pg.*`-Klassen liegen seit Schritt 1 unter
         * `agent/src/Ops/` und sind aus dem Agenten nicht erreichbar; sie
         * werden in dem Beitrag eingetragen, der ihnen einen Aufrufer gibt.
         * Der erste Anlauf hat `pg.server.info` schon in Schritt 1 registriert
         * und die CI rot gemacht: *Code, der als root läuft und zu dem kein Weg
         * führt, ist Angriffsfläche ohne Nutzen*
         * ({@see \Tests\Feature\AgentOperationReachTest}).
         *
         * Kein `remove` davor, und hier ausnahmsweise mit Grund statt mit
         * Reihenfolge: `pg.server.install` legt nichts an, was einem
         * Abonnement gehört — es installiert ein Paket der Distribution. Der
         * Weg zurück gehört dem Betreiber und steht in `RemovalPathTest`.
         */
        $this->register(new PgServerInfo);
        $this->register(new PgServerInstall);

        /*
         * Mit Schritt 4 bekommen sie ihren Aufrufer — `App\Support\Databases`
         * und seine zwei Treiber. `remove` steht auch hier zuerst, aus dem
         * Grund, den `docs/35` teuer bezahlt hat.
         */
        $this->register(new PgDatabaseRemove);
        $this->register(new PgDatabaseCreate);
        $this->register(new PgRoleRemove);
        $this->register(new PgRoleCreate);
        $this->register(new PgRoleGrant);

        // Mit Schritt 5: die Sperre eines Abonnements erreicht die Rollen
        // (docs/38 §11). Der Aufrufer ist App\Support\Databases\PgLifecycle.
        $this->register(new PgRoleLock);

        // Die Messung — wie db.usage am Zeitgeber und ohne Lebenslauf.
        $this->register(new PgUsage);
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
