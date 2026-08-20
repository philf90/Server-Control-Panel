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
     * Die Prüfregeln für die Übersteuerungen eines Abonnements.
     *
     * **Der Unterschied zu {@see self::rules()} ist `sometimes` statt
     * `present`, und er trägt die ganze Bedeutung.** Am Plan muss jedes
     * Kontingent stehen: Ein fehlendes Feld wäre ein Formular aus einer
     * anderen Fassung. Am Abonnement ist ein fehlender Schlüssel dagegen die
     * Aussage „gilt der Plan" — und das ist der Normalfall, nicht die
     * Ausnahme. Ein Abonnement, das jedes Kontingent überschreibt, hängt nicht
     * mehr am Plan; eine Änderung des Plans erreichte es nie wieder.
     *
     * `nullable` steht hier auch bei den beiden Kontingenten, die am Plan
     * nicht unbegrenzt sein dürfen — der Wert `null` bedeutet an dieser Stelle
     * etwas anderes als dort. Er heisst nicht „unbegrenzt", sondern „keine
     * Übersteuerung"; {@see self::overrides()} wirft ihn deshalb heraus,
     * statt ihn abzulegen.
     *
     * @return array<string, mixed>
     */
    public static function overrideRules(): array
    {
        /*
         * **`sometimes` auch am Behälter selbst, und das ist kein Nachlassen.**
         * Am Plan steht `present`: Ein Formular ohne `quotas` ist eines aus
         * einer anderen Fassung. Hier ist „gar keine Übersteuerung" der
         * häufigste Fall — und ein leeres Feld ist genau das, was eine
         * Formularkodierung nicht übertragen kann: Ein leeres Array
         * verschwindet zwischen Browser und Server spurlos. Mit `present`
         * bekäme derjenige, der die letzte Übersteuerung entfernt, eine
         * Fehlermeldung über ein Feld, das er gerade geleert hat.
         */
        $rules = ['overrides' => ['sometimes', 'nullable', 'array']];

        foreach (Quota::cases() as $quota) {
            $key = 'overrides.'.$quota->value;

            if ($quota->isSelection()) {
                $rules[$key] = ['sometimes', 'nullable', 'array', 'min:1'];
                $rules[$key.'.*'] = ['string', Rule::in(Quota::PHP_VERSIONS)];

                continue;
            }

            $rules[$key] = ['sometimes', 'nullable', 'integer', 'min:'.$quota->minimum(), 'max:'.$quota->maximum()];
        }

        return $rules;
    }

    /**
     * Die deutschen Namen zu den Feldern aus {@see self::rules()} und
     * {@see self::overrideRules()}.
     *
     * **Warum nicht in `lang/de/validation.php`.** Diese Feldnamen entstehen
     * erst beim Ausführen, aus {@see Quota::cases()} und {@see Feature::cases()}
     * — eine Abschrift in der Sprachdatei wäre eine zweite Liste, und die
     * zweite ist die, die beim nächsten Kontingent vergessen wird. Der Name
     * kommt deshalb aus derselben Aufzählung wie die Regel selbst; das ist
     * dieselbe Aufteilung, aus der diese Klasse überhaupt entstanden ist.
     *
     * Ohne sie liest der Betreiber „Das Feld quotas.disk mb muss vorhanden
     * sein" (`docs/64`, Befund 15).
     *
     * @return array<string,string>
     */
    public static function names(): array
    {
        $names = [
            'quotas' => 'Kontingente',
            'features' => 'Merkmale',
            'overrides' => 'Übersteuerungen',
        ];

        foreach (Quota::cases() as $quota) {
            // Beide Präfixe, weil beide Regelsätze dieselben Kontingente
            // benennen — am Plan `quotas.`, am Abonnement `overrides.`.
            foreach (['quotas.', 'overrides.'] as $prefix) {
                $names[$prefix.$quota->value] = $quota->label();

                if ($quota->isSelection()) {
                    // Der Eintrag für die einzelne Auswahl. Ohne ihn steht dort
                    // „Das Feld quotas.php versions.0 …".
                    $names[$prefix.$quota->value.'.*'] = $quota->label();
                }
            }
        }

        foreach (Feature::cases() as $feature) {
            $names['features.'.$feature->value] = $feature->label();
        }

        return $names;
    }

    /**
     * Aus einer Formulareingabe die Übersteuerungen eines Abonnements.
     *
     * **Was fehlt, bleibt weg.** Das ist der Unterschied zu
     * {@see self::normalize()}, die Lücken mit Vorgabewerten füllt: Hier wäre
     * ein Vorgabewert eine stille Loslösung vom Plan. Ein Schlüssel steht nur
     * dann im Ergebnis, wenn er in der Eingabe stand und einen Wert trägt.
     *
     * Der Rückgabewert ist `null`, wenn nichts übersteuert wird — nicht ein
     * leeres Array. `{}` in der Datenbank sähe für jeden Leser aus wie „hier
     * war mal etwas".
     *
     * @param  array<mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function overrides(array $input): ?array
    {
        $overrides = [];

        foreach (Quota::cases() as $quota) {
            if (! array_key_exists($quota->value, $input)) {
                continue;
            }

            $value = $input[$quota->value];

            if ($value === null || $value === '') {
                continue;
            }

            if ($quota->isSelection()) {
                $versions = self::versions($value);

                if ($versions !== []) {
                    $overrides[$quota->value] = $versions;
                }

                continue;
            }

            $overrides[$quota->value] = max($quota->minimum(), min($quota->maximum(), (int) $value));
        }

        return $overrides === [] ? null : $overrides;
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
