<?php

declare(strict_types=1);

namespace App\Support\Brand;

use Illuminate\Http\UploadedFile;

/**
 * Das Logo des Betreibers — Ablage, Prüfung und Auslieferung (B6).
 *
 * ## Kein SVG, und das ist eine Sicherheitsentscheidung
 *
 * Ein SVG ist ein Dokument und kein Bild: Es darf `<script>` enthalten, und
 * ausgeliefert vom **eigenen** Ursprung läuft dieses Skript in der Sitzung
 * jedes Betrachters. Das Logo steht ausgerechnet auf der Anmeldeseite — der
 * einen Seite, die jeder Besucher ohne Konto sieht.
 *
 * > **Eine Datei, die der Betreiber hochlädt und die das Panel unter seinem
 * > eigenen Ursprung ausliefert, ist Code, sobald ihr Format welchen zulässt.**
 *
 * Erlaubt sind deshalb PNG, JPEG und WebP — drei Formate, die ein Bild sind
 * und sonst nichts.
 *
 * ## Der Typ kommt aus der Datei und nicht aus dem Umschlag
 *
 * `UploadedFile::getMimeType()` liest den Inhalt; `getClientMimeType()` liest,
 * was der Browser behauptet. Ausgeliefert wird der Typ aus **unserer**
 * Positivliste, nachgeschlagen über den gelesenen — nie der übergebene.
 *
 * > **Ein Typ, den der Absender mitschickt, ist eine Behauptung und keine
 * > Messung.**
 *
 * ## Und die Auslieferung ist öffentlich, mit Absicht
 *
 * Die Anmeldeseite hat kein angemeldetes Konto. Ein Logo hinter der Anmeldung
 * wäre auf der einen Seite unsichtbar, für die es das Kriterium gibt. Was
 * dabei nach draussen geht, ist ein Bild, das der Betreiber selbst gewählt
 * hat, um es zu zeigen.
 */
final class Logo
{
    /**
     * Die Formate, die durchkommen — gelesener Typ auf Endung.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Wie gross ein Logo werden darf.
     *
     * **256 KiB, und die Zahl hat einen Grund.** Das Bild steht auf der
     * Anmeldeseite, und die lädt, bevor irgendetwas anderes lädt. Ein Logo,
     * das grösser ist als das ganze Stylesheet dieses Panels (gemessen rund
     * 130 KiB), verschiebt den Aufbau der einen Seite, auf die es ankommt.
     */
    public const MAX_BYTES = 262_144;

    /** Wie die abgelegte Datei heisst — die Endung sagt, was drinsteht. */
    public const BASENAME = 'logo';

    public function directory(): string
    {
        return storage_path('app/branding');
    }

    /**
     * Die abgelegte Datei zu einem Namen — oder `null`.
     *
     * Der Name kommt aus der Ablage und wird **nicht** vom Aufrufer gebildet:
     * Ein Pfad, der aus einer Eingabe entsteht, ist die Stelle, an der jemand
     * `../` unterbringt. Geprüft wird trotzdem, dass die Endung eine bekannte
     * ist — eine beschädigte Ablage soll keine beliebige Datei ausliefern.
     */
    public function path(?string $name): ?string
    {
        if ($name === null || ! in_array($name, self::names(), true)) {
            return null;
        }

        $pfad = $this->directory().'/'.$name;

        return is_file($pfad) ? $pfad : null;
    }

    /**
     * Die drei Namen, die es überhaupt geben kann.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_map(
            static fn (string $endung): string => self::BASENAME.'.'.$endung,
            self::TYPES,
        ));
    }

    /**
     * Der Typ, mit dem eine abgelegte Datei ausgeliefert wird.
     *
     * Nachgeschlagen über die Endung in derselben Liste, mit der sie
     * hereinkam — nicht über eine zweite Zuordnung.
     */
    public static function typeOf(string $name): ?string
    {
        $endung = pathinfo($name, PATHINFO_EXTENSION);

        return array_search($endung, self::TYPES, true) ?: null;
    }

    /**
     * Was an einer hochgeladenen Datei nicht stimmt — oder `null`.
     *
     * Gibt einen Satz zurück und keinen Schlüssel: Er steht so, wie er ist,
     * über dem Formular.
     */
    public function refusal(UploadedFile $file): ?string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            return sprintf(
                'Das Bild ist %d KB gross; erlaubt sind %d KB.',
                (int) round((int) $file->getSize() / 1024),
                (int) (self::MAX_BYTES / 1024),
            );
        }

        $typ = (string) $file->getMimeType();

        if (! array_key_exists($typ, self::TYPES)) {
            return sprintf(
                'Nur PNG, JPEG und WebP — diese Datei ist %s. SVG ist ausgeschlossen: '
                .'Es darf Skript enthalten, und das Logo steht auf der Anmeldeseite.',
                $typ === '' ? 'nicht lesbar' : $typ,
            );
        }

        return null;
    }

    /**
     * Ablegen und den Namen zurückgeben.
     *
     * **Die alten Dateien gehen mit.** Ein Wechsel von PNG auf WebP liesse
     * sonst die alte liegen, und beim nächsten Lesen entschiede die Reihenfolge
     * der Liste, welche ausgeliefert wird.
     */
    public function store(UploadedFile $file): string
    {
        $this->forget();

        $verzeichnis = $this->directory();

        if (! is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0o750, true);
        }

        $name = self::BASENAME.'.'.self::TYPES[(string) $file->getMimeType()];
        $file->move($verzeichnis, $name);

        return $name;
    }

    /** Jede mögliche Ablage entfernen. */
    public function forget(): void
    {
        foreach (self::names() as $name) {
            $pfad = $this->directory().'/'.$name;

            if (is_file($pfad)) {
                unlink($pfad);
            }
        }
    }
}
