<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
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

    private ?MailSettings $mail = null;

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
