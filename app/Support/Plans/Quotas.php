<?php

declare(strict_types=1);

namespace App\Support\Plans;

use Illuminate\Validation\Rule;

/**
 * Kontingente prüfen, normalisieren und beschreiben.
 *
 * Die drei Dinge, die sonst auseinanderlaufen, kommen hier aus derselben
 * Aufzählung: die Prüfregeln beim Speichern, die Form der abgelegten Daten und
 * die Beschreibung, aus der das Formular seine Felder baut. Die Oberfläche
 * kennt kein einziges Kontingent beim Namen — sie rendert, was sie bekommt.
 * Ein neues Kontingent braucht deshalb keine Zeile Vue.
 */
final class Quotas
{
    /**
     * Die Prüfregeln für ein Formular, das `quotas` und `features` schickt.
     *
     * `present` und nicht `sometimes`: Ein Formular, das ein Kontingent
     * weglässt, ist ein Formular, das nicht zu dieser Fassung passt — und
     * stillschweigend auf einen Vorgabewert zu fallen hiesse, dem Betreiber
     * einen Wert unterzuschieben, den er nicht gesehen hat.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'quotas' => ['required', 'array'],
            'features' => ['required', 'array'],
        ];

        foreach (Quota::cases() as $quota) {
            $key = 'quotas.'.$quota->value;

            if ($quota->isSelection()) {
                $rules[$key] = ['present', 'array', 'min:1'];
                $rules[$key.'.*'] = ['string', Rule::in(Quota::PHP_VERSIONS)];

                continue;
            }

            $rules[$key] = $quota->allowsUnlimited()
                ? ['present', 'nullable', 'integer', 'min:'.$quota->minimum(), 'max:'.$quota->maximum()]
                : ['present', 'integer', 'min:'.$quota->minimum(), 'max:'.$quota->maximum()];
        }

        foreach (Feature::cases() as $feature) {
            $rules['features.'.$feature->value] = ['present', 'boolean'];
        }

        return $rules;
    }

    /**
     * Auf die bekannten Schlüssel zurückschneiden.
     *
     * In beide Richtungen: Was fehlt, kommt aus dem Vorgabewert dazu; was
     * nicht in den Katalog gehört, fliegt raus. Ohne den zweiten Teil würde
     * ein umbenanntes Kontingent für immer als Leiche im JSON stehen und
     * irgendwann von einer neuen Fassung wieder gelesen — mit einem Wert aus
     * einer Zeit, in der er etwas anderes bedeutete.
     *
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $normalized = [];

        foreach (Quota::cases() as $quota) {
            $value = $input[$quota->value] ?? null;

            if ($quota->isSelection()) {
                $normalized[$quota->value] = self::versions($value);

                continue;
            }

            if ($value === null || $value === '') {
                // Unbegrenzt — aber nur, wo das erlaubt ist. Sonst der
                // Vorgabewert: Ein Plan ohne Speichergrenze kommt nicht
                // dadurch zustande, dass jemand ein Feld leer lässt.
                $normalized[$quota->value] = $quota->allowsUnlimited() ? null : $quota->default();

                continue;
            }

            $normalized[$quota->value] = max($quota->minimum(), min($quota->maximum(), (int) $value));
        }

        return $normalized;
    }

    /**
     * Dasselbe für die Freigaben.
     *
     * @param  array<mixed>  $input
     * @return array<string, bool>
     */
    public static function normalizeFeatures(array $input): array
    {
        $normalized = [];

        foreach (Feature::cases() as $feature) {
            $normalized[$feature->value] = (bool) ($input[$feature->value] ?? false);
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (Quota::cases() as $quota) {
            $defaults[$quota->value] = $quota->default();
        }

        return $defaults;
    }

    /** @return array<string, bool> */
    public static function featureDefaults(): array
    {
        $defaults = [];

        foreach (Feature::cases() as $feature) {
            $defaults[$feature->value] = $feature->default();
        }

        return $defaults;
    }

    /**
     * Der Katalog für die Oberfläche.
     *
     * @return array{quotas: list<array<string, mixed>>, features: list<array<string, string>>, php_versions: list<string>}
     */
    public static function catalog(): array
    {
        return [
            'quotas' => array_map(static fn (Quota $quota): array => [
                'key' => $quota->value,
                'label' => $quota->label(),
                'hint' => $quota->hint(),
                'unit' => $quota->unit(),
                'selection' => $quota->isSelection(),
                'unlimited' => $quota->allowsUnlimited(),
                'minimum' => $quota->minimum(),
                'maximum' => $quota->maximum(),
            ], Quota::cases()),
            'features' => array_map(static fn (Feature $feature): array => [
                'key' => $feature->value,
                'label' => $feature->label(),
                'hint' => $feature->hint(),
            ], Feature::cases()),
            'php_versions' => Quota::PHP_VERSIONS,
        ];
    }

    /**
     * Ein Kontingent als Text — für Listen und Übersichten.
     *
     * Die eine Stelle, an der aus `null` „unbegrenzt" wird. Stünde diese
     * Umsetzung in der Oberfläche, hätte jede Liste ihre eigene, und eine
     * davon zeigte irgendwann eine leere Zelle.
     */
    public static function format(Quota $quota, mixed $value): string
    {
        if ($quota->isSelection()) {
            $versions = self::versions($value);

            return $versions === [] ? 'keine' : implode(', ', $versions);
        }

        if ($value === null) {
            return 'unbegrenzt';
        }

        $number = number_format((int) $value, 0, ',', '.');
        $unit = $quota->unit();

        return $unit === null ? $number : $number.' '.$unit;
    }

    /**
     * Aus einem beliebigen Eingabewert die gültigen PHP-Versionen lesen.
     *
     * @return list<string>
     */
    private static function versions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $versions = [];

        foreach (Quota::PHP_VERSIONS as $version) {
            if (in_array($version, $value, true)) {
                $versions[] = $version;
            }
        }

        // Über den Katalog gelaufen und nicht über die Eingabe: Das sortiert
        // die Auswahl nebenbei und lässt Unbekanntes gar nicht erst durch.
        return $versions;
    }
}
