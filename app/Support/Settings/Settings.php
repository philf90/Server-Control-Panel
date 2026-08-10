<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
use App\Support\Web\PhpSelection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Der Zugang zu den Einstellungen des Betreibers.
 *
 * **Warum das nicht direkt über das Modell läuft.** Die Mailkonfiguration wird
 * beim Hochfahren der Anwendung gelesen — also auch dann, wenn es die Tabelle
 * noch gar nicht gibt: bei `migrate` auf einem frischen System, im Installer,
 * in jedem Artisan-Kommando vor der ersten Migration. Ein Modellzugriff wäre
 * dort ein Absturz mit einer Meldung über eine fehlende Tabelle, und zwar
 * ausgerechnet in dem Lauf, der sie anlegen soll.
 *
 * Deshalb fängt `mail()` beides ab: die fehlende Tabelle und den Fehler beim
 * Entschlüsseln. Der zweite Fall ist der unangenehmere — wechselt der
 * `APP_KEY`, sind die abgelegten Zugangsdaten nicht mehr lesbar. Die Antwort
 * darauf sind leere Einstellungen und kein Fehler: Ohne Mailversand läuft das
 * Panel weiter, mit einer Ausnahme beim Hochfahren nicht mehr.
 */
final class Settings
{
    private const MAIL = 'mail';

    /**
     * Die auf dem Server installierten PHP-Versionen.
     *
     * Kein Geheimnis und trotzdem in derselben Ablage: Sie ist die eine
     * Stelle für Zustand, den der Betreiber setzt und der keine eigene
     * Tabelle rechtfertigt. Verschlüsselt ist sie, weil es die Ablage ist —
     * nicht, weil die Liste es bräuchte.
     */
    private const PHP_VERSIONS = 'php_versions';

    /**
     * Ob dieses Panel PostgreSQL überhaupt anbietet.
     *
     * **Das ist der Betreiberschalter aus `docs/38 §7` und nicht der Zustand
     * des Servers.** Die beiden auseinanderzuhalten ist der ganze Punkt: Ob
     * ein Cluster läuft, beantwortet `pg.server.info` und niemand sonst — eine
     * im Panel gemerkte Fassung wäre die, die veraltet — dieselbe Begründung
     * wie bei `bind-address` im `DatabaseSettingsController`.
     * Was hier steht, ist die Absicht: *Kunden dürfen PostgreSQL-Datenbanken
     * anlegen.*
     *
     * Der Unterschied ist nicht theoretisch. Ein Server kann ein PostgreSQL
     * tragen, das dem Betreiber gehört — für sein eigenes Zeug, mit seinen
     * eigenen Rollen. Ohne diesen Schalter fiele die Kundenfläche in dem
     * Augenblick auf, in dem `pg_lsclusters` etwas findet, und die erste
     * Kundendatenbank landete in einem Cluster, den niemand dafür vorgesehen
     * hat.
     */
    private const POSTGRES = 'postgresql';

    /** Was der Messlauf über die Dateisystem-Quota gesehen hat. */
    private const DISK_QUOTA = 'usage.disk_quota';

    private ?MailSettings $mail = null;

    /** @var list<string>|null */
    private ?array $phpVersions = null;

    public function mail(): MailSettings
    {
        if ($this->mail !== null) {
            return $this->mail;
        }

        return $this->mail = MailSettings::fromArray($this->read(self::MAIL));
    }

    public function saveMail(MailSettings $settings): void
    {
        Setting::query()->updateOrCreate(['key' => self::MAIL], ['value' => $settings->toArray()]);

        $this->mail = $settings;
    }

    /**
     * Welche PHP-Versionen auf dem Server liegen.
     *
     * **Leer heisst „nicht bekannt" und wird wie „keine" behandelt.** Vor dem
     * ersten Lauf von `php.versions` weiss das Panel es nicht; eine Domain
     * mit einer Version anzulegen, die es vielleicht nicht gibt, endet in
     * einem Server-Block, den der Agent zurückweist. Siehe
     * {@see PhpSelection::installed()}.
     *
     * @return list<string>
     */
    public function phpVersions(): array
    {
        if ($this->phpVersions !== null) {
            return $this->phpVersions;
        }

        $stored = $this->read(self::PHP_VERSIONS)['installed'] ?? null;

        if (! is_array($stored)) {
            return $this->phpVersions = [];
        }

        return $this->phpVersions = array_values(array_filter($stored, is_string(...)));
    }

    /** @param list<string> $versions */
    public function savePhpVersions(array $versions): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::PHP_VERSIONS],
            // `toDateTimeString` und nicht ISO: Die Angabe steht in der
            // Oberfläche, und dort sieht sie aus wie jeder andere Zeitpunkt im
            // Panel. Ein `2026-08-04T11:05:18+00:00` daneben wäre dieselbe
            // Auskunft in einer zweiten Schreibweise.
            ['value' => ['installed' => array_values($versions), 'checked_at' => now()->toDateTimeString()]],
        );

        $this->phpVersions = array_values($versions);
    }

    /** Wann zuletzt nachgesehen wurde — `null`, wenn noch nie. */
    public function phpVersionsCheckedAt(): ?string
    {
        $at = $this->read(self::PHP_VERSIONS)['checked_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /**
     * Bietet dieses Panel PostgreSQL an?
     *
     * **Der Grundzustand ist „nein", und zwar auch nach einem Update.** Ein
     * Bestandsserver, auf dem P5b ankommt, bekommt keine zweite
     * Datenbankfläche, weil jemand ein Paket aktualisiert hat — dieselbe
     * Richtung wie die Mandantenklammer: im Zweifel nichts.
     */
    public function postgres(): bool
    {
        return ($this->read(self::POSTGRES)['offered'] ?? false) === true;
    }

    /**
     * Den Schalter umlegen — aus `srvpanel db --postgresql=on|off`.
     *
     * Wann, steht mit dabei. Nicht aus Ordnungsliebe: Wer auf einer stillen
     * Kundenfläche steht und wissen will, seit wann sie still ist, hat sonst
     * keine Antwort ausser dem Prüfpfad.
     */
    public function savePostgres(bool $offered): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::POSTGRES],
            ['value' => ['offered' => $offered, 'changed_at' => now()->toDateTimeString()]],
        );
    }

    /**
     * Ob das Dateisystem unter `/var/www/vhosts` eine Benutzerquota führt.
     *
     * **Gemessen wird das nicht hier, sondern jede Viertelstunde ohnehin.**
     * `subscription.usage` liest die Quota-Datei mit `repquota`; scheitert das,
     * kommt `available: false` samt Grund zurück. Diese Antwort stand bis zum
     * 10. August 2026 nur im Journal des Messlaufs — die Übersicht wusste
     * nichts davon, und ein Betreiber erfuhr vom fehlenden Quota-System erst,
     * wenn er ein Abonnement anlegte.
     *
     * **Die Mount-Option beweist nichts, und das ist gemessen.** Auf
     * `cloudsrv24` stand `rw,relatime,quota,usrquota` in `/proc/mounts` und
     * `quotaon -p /` sagte trotzdem `is off`: Die Quotadatei war nie angelegt
     * worden. Wer nur die Optionen liest, meldet Bereitschaft, wo keine ist —
     * deshalb steht hier das Ergebnis eines Leseversuchs und keine Ableitung.
     *
     * @return array{available: bool|null, reason: string|null, checked_at: string|null}
     */
    public function diskQuota(): array
    {
        $value = $this->read(self::DISK_QUOTA);

        return [
            // Drei Werte. `null` heisst „noch nie gemessen" — der Timer läuft
            // im Viertelstundentakt, und vor seinem ersten Lauf soll die
            // Übersicht schweigen statt Entwarnung zu geben.
            'available' => is_bool($value['available'] ?? null) ? $value['available'] : null,
            'reason' => is_string($value['reason'] ?? null) ? $value['reason'] : null,
            'checked_at' => is_string($value['checked_at'] ?? null) ? $value['checked_at'] : null,
        ];
    }

    /** Was der Messlauf gesehen hat — mit Zeitstempel, wie bei den PHP-Fassungen. */
    public function saveDiskQuota(bool $available, ?string $reason): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::DISK_QUOTA],
            ['value' => [
                'available' => $available,
                'reason' => $available ? null : $reason,
                'checked_at' => now()->toDateTimeString(),
            ]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $key): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            $value = Setting::query()->find($key)?->value;

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }
}
