<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Models\Subscription;
use SrvPanel\Agent\PhpVersions;

/**
 * Die Kontingente aus §5.2 — als Aufzählung und nicht als Zeichenketten.
 *
 * **Warum eine Aufzählung, wenn die Werte doch als JSON liegen.** Der
 * Speicherort ist JSON, weil ein Abonnement einzelne Werte übersteuert und
 * „nicht gesetzt" von „auf 0 gesetzt" unterscheiden muss (siehe die Migration).
 * Das heisst aber nicht, dass die *Schlüssel* frei sein dürfen. Bis hierher
 * standen sie an vier Stellen als Literale: in der Factory, in der Policy, im
 * Formular und in der Prüfung. Ein Tippfehler in einer davon hätte niemand
 * bemerkt — `datenbanken` statt `databases` liefert kein Kontingent, und „kein
 * Kontingent" sieht aus wie „unbegrenzt".
 *
 * Diese Aufzählung ist ab jetzt die einzige Quelle. Wer ein Kontingent
 * dazunimmt, schreibt es hier hin und bekommt Beschriftung, Einheit,
 * Prüfregel und Formularfeld daraus; wer eines nennt, das es nicht gibt,
 * scheitert beim Übersetzen und nicht im Betrieb.
 *
 * **Die Bedeutung von `null` ist festgelegt und gilt überall:** kein Wert
 * heisst unbegrenzt. `0` heisst null Stück — eine Aussage, keine Lücke. Genau
 * deshalb arbeitet {@see Subscription::quota()} mit
 * `array_key_exists` und nicht mit `??`.
 */
enum Quota: string
{
    /**
     * Die PHP-Versionen, die das Panel kennt.
     *
     * Sie steht nicht in einer Konfigurationsdatei, weil sie kein
     * Betreiberwunsch ist: Für jede dieser Versionen muss es eine
     * FPM-Vorlage, einen Paketnamen und einen Handler geben. Eine Version
     * hinzunehmen heisst, diese drei Dinge mitzuliefern.
     *
     * **Seit P3 steht die Liste im Agenten** — dort, wo aus einer Version ein
     * Paketname und ein Dateipfad wird. Hier stand sie zuerst, und das war die
     * falsche Richtung: Der Agent glaubt dem Panel nichts und hätte die
     * Angabe ohnehin gegen eine eigene Liste prüfen müssen. Zwei Listen, und
     * die im Panel wäre die gepflegte gewesen.
     *
     * @var list<string>
     */
    public const PHP_VERSIONS = PhpVersions::CATALOG;

    case DiskMb = 'disk_mb';
    case TrafficGb = 'traffic_gb';
    case Domains = 'domains';
    case Subdomains = 'subdomains';
    case Databases = 'databases';
    case FtpAccounts = 'ftp_accounts';
    case CronJobs = 'cron_jobs';
    case FpmProcesses = 'fpm_processes';
    case PhpVersions = 'php_versions';

    public function label(): string
    {
        return match ($this) {
            self::DiskMb => 'Speicherplatz',
            self::TrafficGb => 'Traffic je Monat',
            self::Domains => 'Domains',
            self::Subdomains => 'Subdomains',
            self::Databases => 'Datenbanken',
            self::FtpAccounts => 'FTP-Konten',
            self::CronJobs => 'Cronjobs',
            self::FpmProcesses => 'FPM-Prozesse',
            self::PhpVersions => 'PHP-Versionen',
        };
    }

    /**
     * Was das Kontingent im System tatsächlich bewirkt.
     *
     * Steht im Formular unter dem Feld. Ein Betreiber, der „Traffic" einträgt,
     * soll wissen, dass die Zahl gemessen und nicht durchgesetzt wird — sonst
     * hält er eine Warnschwelle für eine Sperre.
     */
    public function hint(): string
    {
        return match ($this) {
            self::DiskMb => 'Erzwungen über die Dateisystem-Quota des Systembenutzers. Ist sie erreicht, schlagen Schreibzugriffe fehl.',
            self::TrafficGb => 'Gemessen, nicht erzwungen. Die Überschreitung erscheint in der Übersicht und löst keine Sperre aus.',
            self::Domains => 'Zählt Haupt- und Addon-Domains. Aliasse zählen nicht mit.',
            self::Subdomains => 'Über alle Domains des Abonnements zusammen.',
            self::Databases => 'MariaDB-Schemata. Der zugehörige Datenbankbenutzer zählt nicht getrennt.',
            self::FtpAccounts => 'Zusätzliche FTP-Konten. Der Systembenutzer des Abonnements zählt nicht mit.',
            self::CronJobs => 'Einträge in der Crontab des Systembenutzers.',
            self::FpmProcesses => 'Obergrenze des PHP-FPM-Pools (pm.max_children). Bestimmt, wie viele Anfragen gleichzeitig laufen.',
            self::PhpVersions => 'Welche Handler in den vhost-Vorlagen ausgewählt werden dürfen.',
        };
    }

    /** Die Einheit hinter dem Eingabefeld. `null`, wenn gezählt wird. */
    public function unit(): ?string
    {
        return match ($this) {
            self::DiskMb => 'MB',
            self::TrafficGb => 'GB',
            default => null,
        };
    }

    /** Eine Auswahl aus einer festen Liste statt einer Zahl. */
    public function isSelection(): bool
    {
        return $this === self::PhpVersions;
    }

    /**
     * Darf dieses Kontingent unbegrenzt sein?
     *
     * Zwei dürfen es nicht, und beide aus demselben Grund: Sie teilen sich eine
     * Ressource, die der ganze Server teilt. Ein Abonnement ohne Speichergrenze
     * kann das Dateisystem füllen und nimmt jedes andere mit; ein FPM-Pool ohne
     * Obergrenze kann den Arbeitsspeicher belegen und nimmt ebenfalls jedes
     * andere mit. Die übrigen Kontingente kosten im schlimmsten Fall Ordnung.
     */
    public function allowsUnlimited(): bool
    {
        return $this !== self::DiskMb && $this !== self::FpmProcesses;
    }

    /** Der kleinste sinnvolle Wert. */
    public function minimum(): int
    {
        // Ein Abonnement mit 0 MB Platz oder 0 FPM-Prozessen ist kein
        // eingeschränktes Abonnement, sondern ein kaputtes: Das Verzeichnis
        // liesse sich nicht anlegen, der Pool nicht starten. Alles andere darf
        // auf 0 stehen — „keine Datenbanken" ist ein gültiges Paket.
        return match ($this) {
            self::DiskMb => 64,
            self::FpmProcesses => 1,
            default => 0,
        };
    }

    /**
     * Die Obergrenze der Eingabe.
     *
     * Keine Richtlinie, sondern ein Vertipper-Fang: Wer bei den Domains eine
     * Null zu viel setzt, merkt es hier und nicht, wenn ein Kunde 500 vhosts
     * anlegt.
     */
    public function maximum(): int
    {
        return match ($this) {
            self::DiskMb => 100_000_000,   // 100 TB
            self::TrafficGb => 1_000_000,  // 1 PB
            self::FpmProcesses => 512,
            default => 10_000,
        };
    }

    /**
     * Der Wert eines neuen Plans, bevor jemand ihn anfasst.
     *
     * Kein `null` darunter, und das ist der Punkt: Ein Vorschlag, der
     * „unbegrenzt" heisst, wäre der eine Wert, den niemand bewusst gewählt
     * hat und der trotzdem am weitesten reicht.
     *
     * @return int|list<string>
     */
    public function default(): int|array
    {
        return match ($this) {
            self::DiskMb => 5_120,
            self::TrafficGb => 100,
            self::Domains => 5,
            self::Subdomains => 25,
            self::Databases => 5,
            self::FtpAccounts => 5,
            self::CronJobs => 10,
            self::FpmProcesses => 10,
            self::PhpVersions => ['8.3', '8.4'],
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $quota): string => $quota->value, self::cases());
    }
}
