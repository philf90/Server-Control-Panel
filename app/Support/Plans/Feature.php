<?php

declare(strict_types=1);

namespace App\Support\Plans;

use App\Enums\Permission;
use App\Policies\SubscriptionPolicy;

/**
 * Die Funktionsfreigaben eines Plans (§5.2).
 *
 * **Freigabe und Recht sind zwei verschiedene Dinge.** Die Freigabe sagt, ob
 * ein Abonnement diese Funktion überhaupt hat — sie ist Vertragssache und
 * steht im Plan. Das Recht ({@see Permission}) sagt, wer innerhalb des
 * Abonnements sie benutzen darf, und das entscheidet der Kunde für seine
 * Zusatzbenutzer. Beides muss zutreffen; die Prüfung steht in
 * {@see SubscriptionPolicy::useFeature()}.
 *
 * Die Zuordnung zwischen beiden stand bisher als Zeichenkette in der Policy —
 * `Permission::Dns => 'dns_edit'`. Sie steht jetzt hier, in einer Richtung, in
 * der ein Tippfehler auffällt: Die Aufzählung nennt das Recht, nicht die
 * Zeichenkette den Schlüssel.
 */
enum Feature: string
{
    case DnsEdit = 'dns_edit';
    case CertificateUpload = 'certificate_upload';
    case Backups = 'backups';
    case PhpSettings = 'php_settings';

    public function label(): string
    {
        return match ($this) {
            self::DnsEdit => 'DNS-Einträge bearbeiten',
            self::CertificateUpload => 'Eigene Zertifikate hochladen',
            self::Backups => 'Sicherungen anlegen',
            self::PhpSettings => 'PHP-Einstellungen ändern',
        };
    }

    /**
     * Die Kurzform für Tabellen.
     *
     * „DNS-Einträge bearbeiten, Eigene Zertifikate hochladen, Sicherungen
     * anlegen, PHP-Einstellungen ändern" in einer Zelle ist keine Spalte mehr,
     * sondern ein Absatz — er hat die Planliste auf zwei Zeilen je Plan
     * getrieben und den Blick von den Zahlen weggezogen, um die es dort geht.
     * Im Formular steht weiterhin die ganze Beschriftung: Dort entscheidet
     * jemand, hier überfliegt er nur.
     */
    public function short(): string
    {
        return match ($this) {
            self::DnsEdit => 'DNS',
            self::CertificateUpload => 'Zertifikate',
            self::Backups => 'Sicherungen',
            self::PhpSettings => 'PHP',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::DnsEdit => 'Ohne diese Freigabe verwaltet der Betreiber die Zone; das Abonnement sieht sie nur.',
            self::CertificateUpload => 'Ohne diese Freigabe bleibt nur das automatisch ausgestellte Zertifikat.',
            self::Backups => 'Sicherungen belegen Speicherplatz ausserhalb der Quota des Abonnements.',
            self::PhpSettings => 'Betrifft die je Abonnement überschreibbaren Werte, nicht die Serverwerte.',
        };
    }

    /**
     * Das Recht, dessen Benutzung diese Freigabe voraussetzt.
     *
     * **Kein `null`.** Hier stand `?Permission`, mit der Begründung, eine
     * Freigabe könne an keinem einzelnen Recht hängen — nur gibt es diesen
     * Fall nicht und kann es nicht geben: Eine Freigabe *ist* die Erlaubnis,
     * eine bestimmte Funktion zu benutzen, und jede Funktion hat ihr Recht.
     * Der Nullfall lag in der falschen Richtung; er gehört an
     * {@see self::forPermission()}, wo er tatsächlich vorkommt.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::DnsEdit => Permission::Dns,
            self::CertificateUpload => Permission::Certificates,
            self::Backups => Permission::Backups,
            self::PhpSettings => Permission::PhpSettings,
        };
    }

    /**
     * Die Freigabe, die dieses Recht voraussetzt — die Gegenrichtung.
     *
     * **Hier ist `null` die Regel und keine Ausnahme.** Die meisten Rechte
     * hängen an keiner Freigabe: Dateien lesen, Statistik ansehen, FTP-Konten
     * verwalten gehören zu jedem Abonnement. `null` heisst deshalb „diese
     * Funktion ist nicht planabhängig" und nicht „unbekannt".
     */
    public static function forPermission(Permission $permission): ?self
    {
        foreach (self::cases() as $feature) {
            if ($feature->permission() === $permission) {
                return $feature;
            }
        }

        return null;
    }

    /** Der Wert eines neuen Plans. */
    public function default(): bool
    {
        // Zertifikate hochladen ist aus: Ein hochgeladenes Zertifikat ist das
        // einzige, das der Betreiber nicht selbst erneuern kann — es läuft
        // irgendwann ab, und der Anruf kommt trotzdem bei ihm an.
        return $this !== self::CertificateUpload;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $feature): string => $feature->value, self::cases());
    }
}
