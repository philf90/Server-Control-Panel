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
            ['value' => ['installed' => array_values($versions), 'checked_at' => now()->toIso8601String()]],
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
