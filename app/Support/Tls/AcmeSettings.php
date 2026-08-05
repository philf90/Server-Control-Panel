<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Models\Certificate;
use App\Models\Setting;
use SrvPanel\Agent\Acme\Directories;

/**
 * Was der Betreiber über ACME entschieden hat.
 *
 * Zwei Angaben, und beide sind Betriebsentscheidungen: an welche Adresse die
 * Zertifizierungsstelle schreibt, und ob gegen den Testbetrieb oder produktiv
 * bestellt wird.
 *
 * **Ohne Kontaktadresse wird nichts bestellt.** Der naheliegende Weg wäre, die
 * Adresse des ersten Adminkontos zu nehmen — und das wäre geraten. Eine
 * Kontaktadresse ist die Stelle, an die Let's Encrypt schreibt, wenn ein
 * Zertifikat abzulaufen droht; sie gehört ausdrücklich gesetzt und nicht
 * abgeleitet. Solange sie fehlt, fordert das Panel von selbst keine
 * Zertifikate an.
 *
 * **Der Testbetrieb ist die Vorgabe.** Dieselbe Begründung wie im Agenten:
 * Produktiv sind fünf Fehlversuche je Konto und Stunde die Grenze, und ein
 * Server, der beim Einrichten in eine Sperre läuft, wartet Stunden.
 */
final class AcmeSettings
{
    public const KEY = 'tls';

    /** Die Adresse, an die die Zertifizierungsstelle schreibt — oder `null`. */
    public function contact(): ?string
    {
        $value = $this->value('contact');

        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /**
     * Der Schlüssel der Zertifizierungsstelle — nie eine Adresse.
     *
     * Was hier herauskommt, geht an den Agenten, und der schlägt es in seiner
     * eigenen Positivliste nach ({@see Directories}). Ein unbekannter Wert aus
     * der Datenbank landet damit nicht als URL bei einem Prozess, der als root
     * eine Verbindung aufbaut, sondern als abgewiesenes Argument.
     */
    public function directory(): string
    {
        $value = $this->value('directory');

        return is_string($value) && in_array($value, Directories::keys(), true)
            ? $value
            : Directories::STAGING;
    }

    /** Läuft der Testbetrieb? Die Oberfläche kennzeichnet das. */
    public function staging(): bool
    {
        return $this->directory() === Directories::STAGING;
    }

    /** Fordert das Panel überhaupt Zertifikate an? */
    public function configured(): bool
    {
        return $this->contact() !== null;
    }

    /**
     * Darf für die Oberfläche selbst eines bestellt werden?
     *
     * **Aus dem Testbetrieb nie**, und das ist die wichtigste Zeile dieser
     * Klasse. Ein Staging-Zertifikat ist von einer Zertifizierungsstelle
     * ausgestellt — der Agent hält es damit für vertrauenswürdig und schreibt
     * `Strict-Transport-Security` in den Server-Block. Kein Browser kennt die
     * Wurzel dahinter: Die Warnung bleibt, **und sie lässt sich nicht mehr
     * wegklicken**, weil HSTS genau das verbietet. Der Betreiber wäre aus
     * seinem eigenen Panel ausgesperrt, und der Weg zurück führte über die
     * Einstellungen des Browsers (`docs/27 §7`).
     *
     * Für eine Kundendomain ist derselbe Fall unschön, hier ist er teuer:
     * Wer sich aussperrt, kann die Einstellung nicht mehr ändern, mit der er
     * sich ausgesperrt hat.
     */
    public function mayOrderForPanel(): bool
    {
        return $this->configured() && ! $this->staging();
    }

    /**
     * Darf ein Server-Block für dieses Zertifikat HSTS versprechen?
     *
     * **Der Testbetrieb ist der Grund, warum diese Frage hier steht und nicht
     * im Agenten.** Ein Staging-Zertifikat ist von einer Zertifizierungsstelle
     * ausgestellt — an der Datei ist es also nicht von einem echten zu
     * unterscheiden, nur kennt kein Browser die Wurzel dahinter. Was der Agent
     * beantworten kann, ist „selbstsigniert oder nicht"; ob überhaupt ein
     * Zertifikat gemeint ist, dem jemand trauen soll, weiss das Panel.
     *
     * `docs/27 §7`: Ein Jahr Erzwingung auf eine Domain zu setzen, die morgen
     * kein gültiges Zertifikat mehr hat, nimmt sie vom Netz — beim Panel
     * trifft das den Betreiber, bei einer Kundendomain jeden Besucher.
     */
    public function hsts(?Certificate $certificate): bool
    {
        return $certificate !== null
            && $certificate->source->trusted()
            && ! $this->staging();
    }

    /**
     * Einzelne Angaben ändern, ohne die anderen zu verlieren.
     *
     * **Zusammengelegt und nicht ersetzt.** Unter demselben Schlüssel liegt
     * alles, was zu ACME gehört — heute zwei Angaben, morgen der Zugang eines
     * DNS-Anbieters. Ein `updateOrCreate` mit der halben Ablage löschte die
     * andere Hälfte, und zwar lautlos: Die Bestellung liefe danach ohne
     * Kontaktadresse und damit gar nicht mehr.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $setting = Setting::query()->find(self::KEY);

        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => array_merge($setting->value ?? [], $values)],
        );
    }

    private function value(string $field): mixed
    {
        $setting = Setting::query()->find(self::KEY);

        return $setting?->value[$field] ?? null;
    }
}
