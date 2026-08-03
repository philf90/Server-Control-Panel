<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Rechtekatalog für Zusatzbenutzer (§6.1).
 *
 * **Die Rechte hängen an der Zuordnung, nicht am Konto.** Derselbe Mensch kann
 * in einem Abonnement Dateien schreiben und in einem anderen nur die Statistik
 * sehen. Deshalb liegen diese Werte in der Verknüpfungstabelle
 * `account_subscription` und nicht am Konto.
 *
 * **Lesen und Schreiben von Dateien sind getrennt.** Das ist der einzige
 * Bereich, in dem Plesk das ebenfalls trennt, und der Grund ist praktisch: Ein
 * Entwickler soll ein Fehlerprotokoll ansehen können, ohne die Anwendung
 * überschreiben zu können.
 *
 * Kunden und Admins brauchen diesen Katalog nicht — ein Kunde darf in seinem
 * Abonnement alles, was sein Plan freigibt, und ein Admin ohnehin alles. Der
 * Katalog beschreibt nur, was ein Kunde an jemanden weitergeben kann.
 */
enum Permission: string
{
    case FilesRead = 'files_read';
    case FilesWrite = 'files_write';
    case Databases = 'databases';
    case Dns = 'dns';
    case Cron = 'cron';
    case Backups = 'backups';
    case PhpSettings = 'php_settings';
    case Certificates = 'certificates';
    case FtpAccounts = 'ftp_accounts';
    case Statistics = 'statistics';

    public function label(): string
    {
        return match ($this) {
            self::FilesRead => 'Dateien lesen',
            self::FilesWrite => 'Dateien schreiben',
            self::Databases => 'Datenbanken',
            self::Dns => 'DNS',
            self::Cron => 'Cronjobs',
            self::Backups => 'Sicherungen',
            self::PhpSettings => 'PHP-Einstellungen',
            self::Certificates => 'Zertifikate',
            self::FtpAccounts => 'FTP-Konten',
            self::Statistics => 'Statistik',
        };
    }

    /**
     * Aus einer gespeicherten Liste die gültigen Rechte lesen.
     *
     * Unbekannte Werte werden verworfen statt zu einem Fehler zu führen: In der
     * Verknüpfungstabelle kann ein Recht stehen, das es in einer neueren
     * Fassung nicht mehr gibt. Ein Absturz beim Anmelden wäre die schlechtere
     * Antwort auf „dieses Recht kennen wir nicht mehr" als es zu ignorieren —
     * zumal Ignorieren hier die sichere Richtung ist.
     *
     * @return list<self>
     */
    public static function fromStored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $permissions = [];

        foreach ($stored as $value) {
            if (! is_string($value)) {
                continue;
            }

            $permission = self::tryFrom($value);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions, SORT_REGULAR));
    }
}
