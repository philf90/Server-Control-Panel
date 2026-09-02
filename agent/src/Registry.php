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
use SrvPanel\Agent\Ops\CronApply;
use SrvPanel\Agent\Ops\CronRuns;
use SrvPanel\Agent\Ops\DbConsoleCell;
use SrvPanel\Agent\Ops\DbConsoleColumns;
use SrvPanel\Agent\Ops\DbConsoleIndexes;
use SrvPanel\Agent\Ops\DbConsoleRows;
use SrvPanel\Agent\Ops\DbConsoleRowWrite;
use SrvPanel\Agent\Ops\DbConsoleTables;
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
use SrvPanel\Agent\Ops\DnsCheck;
use SrvPanel\Agent\Ops\DnsCredentialForget;
use SrvPanel\Agent\Ops\DnsCredentialList;
use SrvPanel\Agent\Ops\DnsCredentialStore;
use SrvPanel\Agent\Ops\FilesChmod;
use SrvPanel\Agent\Ops\FilesCompress;
use SrvPanel\Agent\Ops\FilesCopy;
use SrvPanel\Agent\Ops\FilesExtract;
use SrvPanel\Agent\Ops\FilesList;
use SrvPanel\Agent\Ops\FilesMkdir;
use SrvPanel\Agent\Ops\FilesMove;
use SrvPanel\Agent\Ops\FilesRead;
use SrvPanel\Agent\Ops\FilesRemove;
use SrvPanel\Agent\Ops\FilesSearch;
use SrvPanel\Agent\Ops\FilesTree;
use SrvPanel\Agent\Ops\FilesUpload;
use SrvPanel\Agent\Ops\FilesWrite;
use SrvPanel\Agent\Ops\PanelProvision;
use SrvPanel\Agent\Ops\PanelTls;
use SrvPanel\Agent\Ops\PanelTlsInfo;
use SrvPanel\Agent\Ops\PanelUpdate;
use SrvPanel\Agent\Ops\PanelVhost;
use SrvPanel\Agent\Ops\PgConsoleCell;
use SrvPanel\Agent\Ops\PgConsoleColumns;
use SrvPanel\Agent\Ops\PgConsoleIndexes;
use SrvPanel\Agent\Ops\PgConsoleRows;
use SrvPanel\Agent\Ops\PgConsoleRowWrite;
use SrvPanel\Agent\Ops\PgConsoleTables;
use SrvPanel\Agent\Ops\PgDatabaseCreate;
use SrvPanel\Agent\Ops\PgDatabaseRemove;
use SrvPanel\Agent\Ops\PgDumpCreate;
use SrvPanel\Agent\Ops\PgDumpImport;
use SrvPanel\Agent\Ops\PgRemoteAccess;
use SrvPanel\Agent\Ops\PgRestore;
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
use SrvPanel\Agent\Ops\SftpAccess;
use SrvPanel\Agent\Ops\SftpCheck;
use SrvPanel\Agent\Ops\SftpKeyApply;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Ops\SubscriptionQuota;
use SrvPanel\Agent\Ops\SubscriptionRemove;
use SrvPanel\Agent\Ops\SubscriptionResume;
use SrvPanel\Agent\Ops\SubscriptionSuspend;
use SrvPanel\Agent\Ops\SubscriptionUsage;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\Ops\SystemInfo;
use SrvPanel\Agent\Ops\SystemLogsList;
use SrvPanel\Agent\Ops\SystemLogsTail;
use SrvPanel\Agent\Ops\SystemPackagesList;
use SrvPanel\Agent\Ops\SystemPackagesRefresh;
use SrvPanel\Agent\Ops\SystemPackagesUnattended;
use SrvPanel\Agent\Ops\SystemPackagesUpgrade;
use SrvPanel\Agent\Ops\SystemReboot;
use SrvPanel\Agent\Ops\SystemRunOutcome;
use SrvPanel\Agent\Ops\SystemSourcesList;
use SrvPanel\Agent\Ops\SystemSourcesToggle;
use SrvPanel\Agent\Ops\SystemUnitsList;
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
        // P6: der Dateimanager. Jede dieser Operationen nimmt einen Pfad vom
        // Kunden entgegen — zulässig, weil sie ihn in einem Chroot deutet und
        // nicht prüft (docs/51 §5).
        $this->register(new FilesList);
        $this->register(new FilesRead);
        $this->register(new FilesWrite);
        $this->register(new FilesMkdir);
        $this->register(new FilesRemove);
        $this->register(new FilesMove);
        $this->register(new FilesCopy);
        $this->register(new FilesChmod);
        $this->register(new FilesUpload);
        $this->register(new FilesExtract);
        $this->register(new FilesCompress);
        $this->register(new FilesSearch);
        $this->register(new FilesTree);

        /*
         * P6 Schritt 8 — SFTP (docs/51 §9).
         *
         * `sftp.key.apply` steht vor `sftp.access`, und die Reihenfolge ist
         * dieselbe Überlegung wie bei den `remove`-Hälften weiter unten: Die
         * Schlüsseldatei ist das, was auf der Platte bleibt. Ihr Weg zurück ist
         * derselbe Aufruf mit leerer Liste — deshalb steht sie mit Begründung in
         * `RemovalPathTest::WRITES_WITHOUT_VERB` und nicht als eigenes Paar.
         */
        $this->register(new SftpKeyApply);
        $this->register(new SftpAccess);
        $this->register(new SftpCheck);

        /*
         * P6 Schritt 9 — Cron (docs/51 §10).
         *
         * `cron.apply` steht vor `cron.runs`, und die Reihenfolge ist dieselbe
         * Überlegung wie bei SFTP: Was auf der Platte bleibt, kommt zuerst. Der
         * Weg zurück ist auch hier derselbe Aufruf — ein Abonnement ohne aktive
         * Jobs bekommt keine leere Datei, sondern gar keine.
         */
        $this->register(new CronApply);
        $this->register(new CronRuns);

        $this->register(new WebserverDetect);
        $this->register(new WebSiteApply);
        $this->register(new WebSiteRemove);
        $this->register(new WebLogsTail);

        // Die Protokolle des Servers — Positivliste in SrvPanel\Agent\Logs.
        $this->register(new SystemLogsList);
        $this->register(new SystemLogsTail);

        // P7b A1 — der Paketstand und die Quellen. Lesen apt und ändern nichts.
        $this->register(new SystemPackagesList);
        $this->register(new SystemPackagesRefresh);
        $this->register(new SystemPackagesUnattended);
        $this->register(new SystemPackagesUpgrade);
        $this->register(new SystemRunOutcome);
        $this->register(new SystemReboot);
        $this->register(new SystemSourcesList);
        $this->register(new SystemUnitsList);
        $this->register(new SystemDiagnose);
        $this->register(new SystemSourcesToggle);

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
         * P7 — DNS-Abgleich (docs/72).
         *
         * Eine einzige Operation, und sie verändert nichts: Das Panel kennt den
         * Sollzustand einer Domain, der Agent misst den Istzustand, und
         * verglichen wird oben. Ein Agent, der den Sollzustand kennte, hätte
         * eine zweite Fassung davon.
         */
        $this->register(new DnsCheck);

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

        /*
         * Mit Schritt 6: Sichern, Zurückspielen, Übernehmen. Ihr Aufrufer ist
         * `App\Support\Databases\Dumps` über `DumpLifecycle::task()`.
         *
         * **Kein `pg.dump.remove` daneben, und das ist keine Auslassung.**
         * `db.dump.remove` entfernt eine Datei, und eine Datei hat kein
         * Datenbanksystem — eine zweite Operation wäre Zeile für Zeile
         * dieselbe. `RemovalPathTest::WITHOUT_REMOVAL` führt die Begründung.
         */
        $this->register(new PgRestore);
        $this->register(new PgDumpCreate);
        $this->register(new PgDumpImport);

        // Die Messung — wie db.usage am Zeitgeber und ohne Lebenslauf.
        $this->register(new PgUsage);

        /*
         * Mit Schritt 10: der Fernzugriff (docs/38 §14).
         *
         * **Zwei Aufrufer und nicht einer**, anders als bei `db.remote.access`.
         * Der Schalter für die Horchadresse gehört dem Betreiber und steht in
         * `srvpanel db --remote=on|off`; die Zeilen in `pg_hba.conf` gehören
         * zum Zugang eines Kunden und kommen aus
         * `App\Support\Databases\RemoteAccess`. Beide rufen dieselbe Operation,
         * und der Unterschied liegt in `mode` — ein Kunde schickt `keep` und
         * löst damit keinen Neustart aus.
         */
        $this->register(new PgRemoteAccess);

        /*
         * P5c: das Datenbankmanagement (docs/46) — **hier steht nichts, und das
         * ist Absicht.**
         *
         * Die zehn `*.console.*`-Klassen liegen seit den Schritten 1 und 2 unter
         * `agent/src/Ops/` und sind aus dem Agenten nicht erreichbar. Sie werden
         * in dem Beitrag eingetragen, der ihnen einen Aufrufer gibt — Schritt 3,
         * `App\Support\Databases\Console`.
         *
         * **Der erste Anlauf hat sie hier schon eingetragen**, wörtlich derselbe
         * Fehler, vor dem der Block über P5b warnt, und mit demselben Ergebnis:
         * {@see \Tests\Feature\AgentOperationReachTest} verlangt zu jeder
         * Operation einen Aufrufer. *Code, der als root läuft und zu dem kein Weg
         * führt, ist Angriffsfläche ohne Nutzen* — und eine Warnung, die
         * danebensteht, hält niemanden auf, der sie beim Schreiben liest und
         * beim Registrieren vergisst.
         *
         * **Keine der zehn bekommt einen Lebenslauf.** Ein eingereihter Vorgang
         * legt seine Argumente in `operations.payload` ab, und dort stünde ein
         * Filterwert oder der Inhalt einer Kundenzeile (`docs/46 §12`).
         *
         * **Mit Schritt 3 stehen sie hier**, weil es sie jetzt ruft:
         * `App\Support\Databases\Console` über `Client::call`.
         */
        $this->register(new DbConsoleTables);
        $this->register(new DbConsoleColumns);
        $this->register(new DbConsoleIndexes);
        $this->register(new DbConsoleRows);
        $this->register(new DbConsoleCell);
        $this->register(new DbConsoleRowWrite);

        $this->register(new PgConsoleTables);
        $this->register(new PgConsoleColumns);
        $this->register(new PgConsoleIndexes);
        $this->register(new PgConsoleRows);
        $this->register(new PgConsoleCell);
        $this->register(new PgConsoleRowWrite);
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
