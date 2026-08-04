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

    /*
     * Die drei Deckel für die PHP-Einstellungen je Domain (§9 P3: „mit vom
     * Plan gedeckelten Grenzen").
     *
     * Sie zählen nichts, sie begrenzen: Eine Domain darf `memory_limit`
     * setzen, aber nicht über den Wert ihres Plans hinaus. Deshalb stehen sie
     * hier und nicht als feste Serverwerte — ein Deckel, der für alle gleich
     * ist, ist kein Unterscheidungsmerkmal zwischen zwei Paketen, und genau
     * dafür gibt es Pläne.
     */
    case PhpMemoryMb = 'php_memory_mb';
    case PhpUploadMb = 'php_upload_mb';
    case PhpExecutionSeconds = 'php_execution_seconds';

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
            self::PhpMemoryMb => 'PHP-Speicher je Anfrage',
            self::PhpUploadMb => 'Größte hochladbare Datei',
            self::PhpExecutionSeconds => 'Laufzeit eines Skripts',
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
            self::PhpMemoryMb => 'Obergrenze für memory_limit je Domain. Ein Skript darüber bricht mit einem Speicherfehler ab.',
            self::PhpUploadMb => 'Obergrenze für upload_max_filesize und post_max_size je Domain. nginx lässt genauso viel durch.',
            self::PhpExecutionSeconds => 'Obergrenze für max_execution_time je Domain. Der Pool beendet eine Anfrage spätestens nach 300 Sekunden.',
        };
    }

    /** Die Einheit hinter dem Eingabefeld. `null`, wenn gezählt wird. */
    public function unit(): ?string
    {
        return match ($this) {
            self::DiskMb => 'MB',
            self::TrafficGb => 'GB',
            self::PhpMemoryMb, self::PhpUploadMb => 'MB',
            self::PhpExecutionSeconds => 's',
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
        return ! in_array($this, [
            self::DiskMb,
            self::FpmProcesses,

            // Die drei PHP-Deckel, und jeder aus demselben Grund wie die
            // beiden darüber: Sie geben eine Ressource frei, die der ganze
            // Server teilt. `memory_limit = -1` lässt eine einzige Anfrage
            // den Arbeitsspeicher belegen; eine unbegrenzte Hochladegröße
            // füllt Datenträger und Speicher zugleich; ein Skript ohne
            // Laufzeitgrenze hält einen der gedeckelten FPM-Plätze, bis
            // jemand nachsieht.
            self::PhpMemoryMb,
            self::PhpUploadMb,
            self::PhpExecutionSeconds,
        ], true);
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

            // Ein Deckel auf 0 wäre kein enges Paket, sondern ein kaputtes:
            // Kein PHP-Skript läuft mit 0 MB, keines in 0 Sekunden.
            self::PhpMemoryMb => 32,
            self::PhpUploadMb => 1,
            self::PhpExecutionSeconds => 5,

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
            self::PhpMemoryMb => 8_192,
            self::PhpUploadMb => 4_096,

            // Mehr als der Pool zulässt, wäre eine Zusage, die das System
            // nicht hält: `request_terminate_timeout` beendet jede Anfrage
            // nach 300 Sekunden.
            self::PhpExecutionSeconds => 300,

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
            self::PhpMemoryMb => 256,
            self::PhpUploadMb => 64,
            self::PhpExecutionSeconds => 60,
        };
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $quota): string => $quota->value, self::cases());
    }
}
